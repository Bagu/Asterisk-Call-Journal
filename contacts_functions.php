<?php
/**
 * Trouve ou crée un contact par nom exact. Retourne ['id'=>int, 'created'=>bool].
 * $manuel = true (saisie, import CSV) : le contact devient manuel s'il venait d'un carnet.
 */
function trouverOuCreerContact(PDO $db, string $nom, bool $manuel = true): array {
    $s = $db->prepare("SELECT id, manuel FROM contacts WHERE nom = ?");
    $s->execute([$nom]);
    $row = $s->fetch();
    if ($row) {
        if ($manuel && !(int)$row['manuel']) {
            $db->prepare("UPDATE contacts SET manuel = 1 WHERE id = ?")->execute([$row['id']]);
        }
        return ['id' => (int)$row['id'], 'created' => false];
    }
    $db->prepare("INSERT INTO contacts (nom, manuel) VALUES (?, ?)")->execute([$nom, (int)$manuel]);
    return ['id' => (int)$db->lastInsertId(), 'created' => true];
}

/**
 * Lie un numéro normalisé à un contact.
 * Retourne ['id'=>int, 'etat'=>'created'|'updated'|'exists'].
 * Le type n'est remplacé que par un type connu. $manuel comme pour trouverOuCreerContact().
 */
function lierNumeroId(PDO $db, int $contactId, string $numNorm, string $type, bool $manuel = true): array {
    $s = $db->prepare("SELECT id, type, manuel FROM numeros WHERE numero = ? AND contact_id = ?");
    $s->execute([$numNorm, $contactId]);
    $row = $s->fetch();
    if ($row) {
        $id = (int)$row['id'];
        if ($manuel && !(int)$row['manuel']) {
            $db->prepare("UPDATE numeros SET manuel = 1 WHERE id = ?")->execute([$id]);
        }
        if ($type !== 'inconnu' && $row['type'] !== $type) {
            $db->prepare("UPDATE numeros SET type = ? WHERE id = ?")->execute([$type, $id]);
            return ['id' => $id, 'etat' => 'updated'];
        }
        return ['id' => $id, 'etat' => 'exists'];
    }
    $db->prepare("INSERT INTO numeros (numero, contact_id, type, manuel) VALUES (?,?,?,?)")
       ->execute([$numNorm, $contactId, $type, (int)$manuel]);
    return ['id' => (int)$db->lastInsertId(), 'etat' => 'created'];
}

/** Lie un numéro normalisé à un contact, saisi à la main. Retourne 'created' | 'updated' | 'exists'. */
function lierNumero(PDO $db, int $contactId, string $numNorm, string $type): string {
    return lierNumeroId($db, $contactId, $numNorm, $type)['etat'];
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