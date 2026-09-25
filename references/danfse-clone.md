# Cloning an official auxiliary document (worked example: DANFSe v2.0)

When a customer or auditor expects the printed document (PDF) to look exactly
like the government's, don't eyeball it. This is the method that produced a
layout whose every text run matched the official PDF to within 0.1 pt
(2026-09-25). It is generic — the same steps apply to a DANFE, a cupom NFC-e,
or any layout you have a reference PDF for.

Privacy first: the reference PDF is a real, private fiscal document. Never
commit it (`.gitignore` it), never paste its data into fixtures, tests or this
skill; keep only the *structure* (see pitfall #27).

## 1. Extract the geometry — no poppler needed

`pdftotext -layout` gives the text but not positions/fonts. `pdftoppm` is often
missing on Windows. Use **`mupdf`** from npm (WebAssembly, no native build):

- Render: `page.toPixmap(Matrix.scale(2,2), ColorSpace.DeviceRGB, false, true).asPNG()`
  → look at it.
- Run a custom `Device` over the page to capture, for each glyph, the font
  name, size (`hypot(trm[2], trm[3])` of `Matrix.concat(trm, ctm)`), x and
  **baseline** y; for each path the fill colour and bounds
  (`path.getBounds(strokeState|null, ctm)`) and stroke width; for each image
  its `ctm` (= position/size on the page).
- Group glyphs of the same baseline/font/size into runs. Spaces are not always
  glyphs — take the *text* from `pdftotext`, the *geometry* from mupdf.
- Images can carry a separate soft mask (`image.getMask()`): a straight
  `toPixmap()` may come out on a black background. If the result looks
  washed-out or black, **crop the logo from a 6× render of the page** instead.
- A QR code is an image: decode it with `jsqr` + `pngjs` to learn *exactly*
  what it encodes (DANFSe: `https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=<50 digits>`).

Findings for DANFSe v2.0 (A4 = **595×842 pt**, not 595.28×841.89): 4 text
columns at x = 11.9 / 156.5 / 301.0 / 445.6 pt; labels Arial Bold 6 pt (7 pt for
section titles), values 7 pt, header text 8/9 pt; section-title cells and the
header band filled `#f2f2f2`; 0.5 pt horizontal rules; 1 pt outer frame and a
three-cell footer box; logo top-left, QR top-right (45×45 pt at 492, 44.8).

## 2. Rebuild with absolute positioning, by BASELINE

Dompdf: use `pt` everywhere, `@page { margin:0; size:595pt 842pt }` and
`setPaper([0,0,595,842])` (the default `a4` is 0.28 pt wider and shifts the
diff). One `position:absolute` div per text, `line-height:1`, with
`top = baseline − size×0.79 − 0.16` (calibrated once against the reference;
recalibrate if you change font). Draw rules as 0.5 pt-high filled divs and cell
backgrounds as filled divs. Keep layout constants and data mapping in ONE
class (`DanfseRenderer` in the reference project) and let the Blade only loop.

Fonts: the original uses *Microsoft Sans Serif* (proprietary, can't be
redistributed). Helvetica/Arial has near-identical metrics; disclose the
substitution. Long values are truncated by words with `" ..."` (measure with
Dompdf's `FontMetrics::getTextWidth`); variable-length blocks (description,
complementary info) wrap and push the rest down.

## 3. Map every field from the AUTHORIZED XML

The whole DANFSe comes from `NFSe/infNFSe` (+ `DPS/infDPS`): key = `Id`
without `NFS`; nNFSe, dhProc, dCompet, nDPS, serie, dhEmi, tpEmit, cStat,
prest (`opSimpNac`, `regApTribSN`), toma, `cServ/cTribNac` (format `dd.dd.dd`),
`xTribNac`, `xDescServ`, `tribMun`, `vServPrest/vServ`, `valores/vLiq`,
`infoCompl/xInfComp`; município/UF from `xLocEmi`/`enderNac`; IBGE shown as
`NN.NNNNN`, CEP `NN.NNN-NNN`. Quirks worth copying because they are what the
government actually prints: the **prestador phone/e-mail come from the DPS
`prest` block, not from `emit`** (so they print `-`); "VALOR LÍQUIDO + IBS/CBS"
prints `R$ 0,00` when there is no IBS/CBS group; empty fields print `-`.
Provide a fallback from the database for engines that don't return the
national XML (fields the government adds print `-`).

## 4. Prove it — three cheap checks

1. `pdftotext -layout` of both PDFs, whitespace-normalized, `diff` → must be empty.
2. Run-by-run comparison: same text, same font weight/size, |Δx|,|Δy| ≤ 0.1 pt.
3. Geometry dump of both: rules, fills, frame, footer, MediaBox identical.
   A pixel diff will still show ~1–2 % (glyph shapes of the substituted font,
   QR module grid) — that is expected; structure must be grey, only text edges red.
Then decode your QR and compare with the reference's, and run it once from
production data (the deployed code, a real authorized note) — text identical.

## Test fixture

Use an authorized XML with the signature stripped and every identity
anonymized (pitfall #27). Assert the mapped values, truncation, cancelled
status, long-description shift, no-XML fallback and the PDF MediaBox.
