<?php
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv ?? [], true) || !in_array('--backup-confirmed', $argv ?? [], true)) { http_response_code(404); exit; }
require 'config.php';
try {
    $pdo->exec("ALTER TABLE fournisseurs ADD COLUMN proxy VARCHAR(255) DEFAULT NULL AFTER mac_address");
    echo "Base de données mise à jour avec succès : Colonne proxy ajoutée !";
} catch(Exception $e) {
    echo "Erreur (la colonne existe peut-être déjà) : " . $e->getMessage();
}
?>
