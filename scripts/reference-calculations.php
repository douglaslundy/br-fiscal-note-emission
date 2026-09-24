<?php
declare(strict_types=1);

/**
 * Reference implementations for the arithmetic this skill's domain-concepts.md
 * and pitfalls.md describe in prose. Use these directly if the target project
 * is PHP. If it isn't, PORT THIS EXACT LOGIC rather than re-deriving the
 * rounding/rateio approach from scratch — the rounding behavior here already
 * had a real bug found and fixed against it (see pitfalls.md #1 and the
 * discount-rateio residual-rounding pattern validated across two independent
 * eval runs). Re-deriving "the obvious way" is exactly how that class of bug
 * gets reintroduced.
 *
 * These are pure functions: no I/O, no framework dependency, safe to unit
 * test in isolation before wiring into a real emission pipeline.
 */

/**
 * ISS "por dentro" — the tax is embedded in the price already charged, never
 * added on top. See domain-concepts.md's ISS section for the legal basis
 * (LC 116/2003 art. 7º) and pitfalls.md #1 for the real bug this guards
 * against (a service invoice that overcharged the customer by the tax rate).
 *
 * @return array{valor_total: float, valor_iss: float}
 */
function calcularTotalComIssPorDentro(float $subtotal, float $desconto, float $aliquotaIssPercent): array
{
    if ($desconto > $subtotal) {
        throw new InvalidArgumentException('Desconto não pode exceder o subtotal.');
    }

    $base = round($subtotal - $desconto, 2);
    $valorIss = round($base * $aliquotaIssPercent / 100, 2);

    return [
        // ISS is composition info, never added to the charged total.
        'valor_total' => $base,
        'valor_iss'   => $valorIss,
    ];
}

/**
 * Ratear um desconto do pedido entre os itens, proporcional ao valor de cada
 * um, garantindo que a soma dos descontos por item bate exatamente com o
 * desconto total (o resíduo de arredondamento vai pro último item — sem
 * isso, um rateio "ingênuo" pode deixar a soma 1 centavo divergente do
 * total, que é motivo real de rejeição de schema).
 *
 * @param list<array{descricao: string, valor_unitario: float, quantidade: float}> $itens
 * @return list<array{descricao: string, valor_unitario: float, quantidade: float, valor_desconto: float}>
 */
function ratearDesconto(array $itens, float $descontoTotal): array
{
    $subtotal = array_sum(array_map(
        static fn (array $i) => $i['valor_unitario'] * $i['quantidade'],
        $itens,
    ));

    if ($descontoTotal > $subtotal) {
        // Bloqueia — nunca capar silenciosamente pra "0 até dar certo".
        throw new InvalidArgumentException('Desconto maior que o subtotal — bloquear, não capar silenciosamente.');
    }

    $restante = round($descontoTotal, 2);
    $resultado = [];
    $ultimoIndice = count($itens) - 1;

    foreach ($itens as $i => $item) {
        $valorItem = $item['valor_unitario'] * $item['quantidade'];

        if ($i === $ultimoIndice) {
            // Último item absorve a diferença de arredondamento — garante
            // que soma(valor_desconto) === descontoTotal exatamente.
            $descontoItem = $restante;
        } else {
            $descontoItem = $subtotal > 0
                ? round(($valorItem / $subtotal) * $descontoTotal, 2)
                : 0.0;
            $restante = round($restante - $descontoItem, 2);
        }

        // Trava de segurança: desconto do item nunca pode exceder o próprio item.
        $descontoItem = min($descontoItem, round($valorItem, 2));

        $resultado[] = [...$item, 'valor_desconto' => $descontoItem];
    }

    return $resultado;
}

/**
 * Aloca o próximo número de documento de forma segura sob concorrência.
 * Chamar dentro de uma transação com lock — este esqueleto assume um PDO
 * já em transação; adapte pro ORM/driver real do projeto, mas mantenha o
 * SELECT ... FOR UPDATE (ou equivalente) e o reaproveitamento de número
 * rejeitado (ver domain-concepts.md, seção Numeração/série).
 */
function alocarProximoNumero(PDO $pdo, string $tabelaConfig, string $colunaProximoNumero): int
{
    $stmt = $pdo->prepare("SELECT {$colunaProximoNumero} FROM {$tabelaConfig} FOR UPDATE");
    $stmt->execute();
    $numero = (int) $stmt->fetchColumn();

    $update = $pdo->prepare("UPDATE {$tabelaConfig} SET {$colunaProximoNumero} = ? ");
    $update->execute([$numero + 1]);

    return $numero;
}
