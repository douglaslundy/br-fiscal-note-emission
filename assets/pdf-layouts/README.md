# Printed-document layouts — reference implementations you can hand to a new project

These are **working, tested files** from the reference project (Laravel +
Dompdf). When the user is building a new fiscal application and needs the
printed/PDF document, don't redesign it from scratch: copy the matching files
below, adapt the few coupling points, and run the tests. What each layout is,
how it was verified, and its limits — read this before promising anything.

| Document | Files | Verified how | Fidelity to the official document |
|---|---|---|---|
| **NFS-e national — DANFSe v2.0** | `danfse/` | text, fonts, sizes and positions compared run-by-run with the government's real PDF (≤ 0.1 pt); rules/cells/frame/footer/MediaBox identical; QR decodes to the same URL; run once from production data | **Clone.** Only difference: values use Helvetica instead of the proprietary *Microsoft Sans Serif* |
| **NF-e — DANFE A4** | `danfe/` | built from the official sample DANFE; rendered from the authorized `nfeProc` XML | Same sections/blocks as the official DANFE; **not** pixel-verified like the DANFSe |
| **NFC-e — 80 mm cupom** | `nfce/` | thermal-receipt layout; QR from the authorized XML | Cupom layout, **not** a clone of a specific official PDF |
| Wiring (which template for which document, file names, QR/barcode helpers) | `wiring/NotaFiscalDocumentoService.php` | used in production | — |

## DANFSe (`danfse/`)

- `DanfseRenderer.php` — ALL layout constants (columns x = 11.9/156.5/301.0/445.6
  pt, baselines, fills, rules) **and** the field mapping from the authorized XML
  (`NFSe/infNFSe` + `DPS/infDPS`), with a database fallback for engines that
  don't return the national XML. Returns plain arrays.
- `danfse.blade.php` — generic: loops over the arrays and draws absolutely
  positioned divs in `pt`. Text is placed by baseline (`top = y − size×0.79 −
  0.16`, calibrated for Helvetica; recalibrate if you change font).
- `danfse-logo.png` — the NFS-e national logo (crop of the government's own PDF,
  gray `#f2f2f2` background baked in to match the header cell).
- `nfse_nacional_autorizada.xml` — **anonymized** authorized-XML fixture
  (fictional company/customer/plate, signature stripped). Structure is real.
- `DanfseRendererTest.php` — asserts mapped values, truncation with `...`,
  cancelled status, long-description shift, no-XML fallback, PDF MediaBox.

Adapt: (1) namespace and the `NotaFiscal` model — the renderer reads
`xml_retorno`, `status`, `ambiente`, `numero`, `chave_acesso`, `valor_total`,
`observacoes`, `emitido_em`, `cliente`; (2) `informacoes_complementares_xml`
accessor (reads `infCpl`/`xInfComp` from the XML, falls back to a snapshot
column — see `references/domain-concepts.md`); (3) paths of the logo and view;
(4) the QR builder in the wiring file (`endroid/qr-code` 6.x; margin 22 px on
a 450 px image reproduces the official quiet zone).

Dependencies: `barryvdh/laravel-dompdf`, `endroid/qr-code` ^6.

Quirks intentionally copied from the government's PDF (don't "fix" them):
prestador phone/e-mail come from the DPS `prest` block (print `-`);
"VALOR LÍQUIDO + IBS/CBS" prints `R$ 0,00` when there is no IBS/CBS group;
empty fields print `-`; the service description of the LC 116 item is
truncated by words with `" ..."` at ~545 pt.

Unconfirmed in the reference sample (only one case appeared): the printed text
for `regApTribSN` 2/3 and `tribISSQN` 2–4 come from the library's enum
descriptions, not from a government PDF — re-check if you use those cases.

## DANFE (`danfe/`) and NFC-e (`nfce/`)

`DanfeRenderer.php` turns the authorized `nfeProc` XML into template data;
`danfe.blade.php` + `danfe_corpo.blade.php` render it; `nota_fiscal_nfce.blade.php`
is the 80 mm cupom (height computed per item count in the wiring file).
Good starting points, tested in the reference project, but if a customer
demands a *pixel-identical* DANFE/cupom, apply the method in
`references/danfse-clone.md` to their reference PDF.

## Using this with a new project — checklist

1. Ask which printed documents the user needs (NFS-e / NF-e / NFC-e) and
   whether they require an exact clone of a government PDF.
2. Copy the folder(s), fix namespaces/model fields, install the two composer
   packages.
3. Wire the template selection like `wiring/NotaFiscalDocumentoService.php`
   (NFS-e → `danfse`, NF-e → DANFE from XML, NFC-e → cupom) and name downloads
   `NFSe-<n>` / `NFe-<n>` / `NFCe-<n>`.
4. Run `DanfseRendererTest`; generate one PDF from a real authorized XML of the
   *user's own* engine and compare (steps 3–4 of `references/danfse-clone.md`).
5. Privacy: never copy a real customer's XML/PDF into fixtures or this skill
   (pitfall #27); the repository may be public.
