<?php
declare(strict_types=1);
require __DIR__ . '/reference-calculations.php';

// ISS por dentro: R$100 a 5% -> total R$100 (nao R$105), iss = R$5
$r = calcularTotalComIssPorDentro(100.0, 0.0, 5.0);
assert($r['valor_total'] === 100.0, "valor_total esperado 100.0, veio {$r['valor_total']}");
assert($r['valor_iss'] === 5.0, "valor_iss esperado 5.0, veio {$r['valor_iss']}");
echo "ISS ok: total={$r['valor_total']} iss={$r['valor_iss']}\n";

// Rateio: 3 itens de 33.33 cada = 99.99 subtotal, desconto de 10.00
// soma dos descontos por item deve bater EXATAMENTE com 10.00
$itens = [
    ['descricao' => 'A', 'valor_unitario' => 33.33, 'quantidade' => 1],
    ['descricao' => 'B', 'valor_unitario' => 33.33, 'quantidade' => 1],
    ['descricao' => 'C', 'valor_unitario' => 33.34, 'quantidade' => 1],
];
$rateado = ratearDesconto($itens, 10.00);
$somaDescontos = array_sum(array_column($rateado, 'valor_desconto'));
assert(abs($somaDescontos - 10.00) < 0.001, "soma dos descontos esperada 10.00, veio {$somaDescontos}");
echo "Rateio ok: soma_descontos={$somaDescontos}\n";
foreach ($rateado as $i) {
    echo "  {$i['descricao']}: desconto={$i['valor_desconto']}\n";
}

// Desconto maior que subtotal deve bloquear (throw), nao capar silenciosamente
try {
    ratearDesconto($itens, 999.00);
    echo "FALHOU: deveria ter lancado excecao\n";
    exit(1);
} catch (InvalidArgumentException $e) {
    echo "Bloqueio ok: " . $e->getMessage() . "\n";
}

echo "TODOS OS TESTES PASSARAM\n";
