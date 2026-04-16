<?php
/**
 * Fonctions d'affichage du journal des appels.
 * Partagées entre index.php et tests.php.
 */

/** Calcule les infos d'affichage d'une ligne d'appel.
 *  Détecte les préfixes ## (redirection) et #* (transfert) absents du cache $speciaux. */
function infosLigne(array $row, array $speciaux, array $nomsParNumero): array {
    $src   = trim($row['src']);
    $dst   = trim($row['dst']);
    $isOut = strlen($src) <= 3;

    $posteRaw   = $isOut ? $src : $dst;
    $posteLabel = $speciaux[$posteRaw]['label']     ?? $posteRaw;
    $posteCat   = $speciaux[$posteRaw]['categorie'] ?? 'local';

	$numExterne = normaliserNumero($isOut ? $dst : $src);
    $numRaw     = $isOut ? $dst : $src;

    // Normalise le préfixe international pour l'affichage (+33 ou 0033 → 0)
    // sans tronquer : on garde le numéro complet lisible
    if (!preg_match('/[*#s]/', $numRaw)) {
        $numRaw = preg_replace('/^\+33/', '0', $numRaw);
        $numRaw = preg_replace('/^0033/',  '0', $numRaw);
    }

    if (mb_strtolower(trim($numRaw), 'UTF-8') === mb_strtolower(NUMERO_MASQUE, 'UTF-8')) {
        $numRaw = NUMERO_MASQUE;
    }

    $noms     = $nomsParNumero[$numExterne] ?? [];
    $numCourt = $isOut ? $dst : $src;

    // Détection des préfixes ## et #* si le numéro est absent du cache de numéros spéciaux
    $numSpecialEntry = $speciaux[$numCourt] ?? null;
    if ($numSpecialEntry === null && preg_match('/^[0-9s*#]+$/', $numCourt)) {
        if (str_starts_with($numCourt, '##') && strlen($numCourt) > 2) {
            $numSpecialEntry = ['label' => t('index.redirect', ['ext' => substr($numCourt, 2)]), 'categorie' => 'system'];
        } elseif (str_starts_with($numCourt, '#*') && strlen($numCourt) > 2) {
            $numSpecialEntry = ['label' => t('index.transfert', ['ext' => substr($numCourt, 2)]), 'categorie' => 'system'];
        }
    }

    return [
        'isOut'      => $isOut,
        'posteLabel' => $posteLabel,
        'numExterne' => $numRaw,
        'numNorm'    => $numExterne,
        'noms'       => $noms,
        'numSpecial' => $numSpecialEntry,
        'posteCat'   => $posteCat,
        'isMasque'   => ($numRaw === NUMERO_MASQUE),
    ];
}

/** Formate une durée en secondes en chaîne lisible. */
function formatDuree(int $sec): string {
    if ($sec <= 0) return '—';
    if ($sec < 60) return "{$sec}s";
    $m = intdiv($sec, 60); $s = $sec % 60;
    return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
}