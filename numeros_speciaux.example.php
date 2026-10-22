<?php
/**
 * Numéros spéciaux créés au premier lancement : [numero, label, categorie].
 * Valeurs d'exemple. Copier ce fichier en numeros_speciaux.local.php (non publié)
 * et y mettre les postes et codes de votre propre dialplan.
 */
return [
    ['**1', 'Répondeur',              'system'],
    ['666', 'Blacklist dernier app.', 'blacklist'],
    ['667', 'Blacklist nº manuel',    'blacklist'],
    ['999', 'Retrait blacklist',      'blacklist'],
    ['201', 'Softphone',              'local'],
    ['202', 'PC',                     'local'],
    ['203', 'Smartphone',             'local'],
    ['204', 'Base DECT',              'local'],
];
