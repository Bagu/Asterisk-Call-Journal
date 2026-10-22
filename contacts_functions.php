<?php
/** Trouve ou crée un contact par nom exact. Retourne ['id'=>int, 'created'=>bool]. */
function trouverOuCreerContact(PDO $db, string $nom): array {
    $s = $db->prepare("SELECT id FROM contacts WHERE nom = ?");
    $s->execute([$nom]);
    $row = $s->fetch();
    if ($row) return ['id' => (int)$row['id'], 'created' => false];
    $db->prepare("INSERT INTO contacts (nom) VALUES (?)")->execute([$nom]);
    return ['id' => (int)$db->lastInsertId(), 'created' => true];
}

/** Lie un numéro normalisé à un contact. Retourne 'created' | 'updated' | 'exists'. */
function lierNumero(PDO $db, int $contactId, string $numNorm, string $type): string {
    $s = $db->prepare("SELECT id, type FROM numeros WHERE numero = ? AND contact_id = ?");
    $s->execute([$numNorm, $contactId]);
    $row = $s->fetch();
    if ($row) {
        if ($type !== 'inconnu' && $row['type'] !== $type) {
            $db->prepare("UPDATE numeros SET type = ? WHERE id = ?")->execute([$type, $row['id']]);
            return 'updated';
        }
        return 'exists';
    }
    $db->prepare("INSERT INTO numeros (numero, contact_id, type) VALUES (?,?,?)")
       ->execute([$numNorm, $contactId, $type]);
    return 'created';
}

/**
 * Retourne le numéro formaté pour l'affichage et l'URL tel: correspondante.
 * - Numéro normalisé 9 chiffres : préfixe 0 ajouté, formaté en xx xx xx xx xx
 *   avec des espaces non-sélectionnables (user-select:none) pour le copier/coller.
 * - Numéro spécial (*, #, s) ou extension courte : retourné tel quel.
 * @return array{tel:string, html:string}
 */
function afficherNumero(string $num): array {
    if (preg_match('/[*#s]/', $num)) {
        // Numéro spécial Asterisk : pas de reformatage
        return ['tel' => $num, 'html' => htmlspecialchars($num)];
    }
    if (preg_match('/^\d{9}$/', $num)) {
        // Numéro normalisé (9 chiffres) : ajouter le 0 initial, formater en xx xx xx xx xx
        $full  = '0' . $num;
        $parts = str_split($full, 2);
        $sp    = '<span class="num-sp"> </span>';
        return [
            'tel'  => $full,
            'html' => implode($sp, array_map('htmlspecialchars', $parts)),
        ];
    }
    // Extension courte ou format inconnu : tel quel
    return ['tel' => $num, 'html' => htmlspecialchars($num)];
}