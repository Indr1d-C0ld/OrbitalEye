<?php
// Scelta del metodo di confronto.

test('metodo automatico: robusto sotto 1,5 m/pixel, SSIM sopra o a scala ignota', function () {
    assert_same('robust', ComparisonRunner::resolveDiffMethod('auto', ['mpp_x' => 0.3, 'mpp_y' => 0.43]));
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('auto', ['mpp_x' => 10.0, 'mpp_y' => 10.0]));
    // L'asse più grossolano decide: 1,2 × 1,7 m non è "alta risoluzione".
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('auto', ['mpp_x' => 1.2, 'mpp_y' => 1.7]));
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('auto', null));
});

test('metodo scelto a mano rispettato, valori sconosciuti ricondotti a SSIM', function () {
    $fine = ['mpp_x' => 0.3, 'mpp_y' => 0.3];
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('ssim', $fine));
    assert_same('absdiff', ComparisonRunner::resolveDiffMethod('absdiff', $fine));
    assert_same('robust', ComparisonRunner::resolveDiffMethod('robust', $fine));
    // Senza scala (o con scala nulla) il robusto non sa quanto mediare.
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('robust', null));
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('robust', ['mpp_x' => 0.0, 'mpp_y' => 0.0]));
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('auto', ['mpp_x' => 0.0, 'mpp_y' => 0.0]));
    assert_same('ssim', ComparisonRunner::resolveDiffMethod('boh', $fine));
});
