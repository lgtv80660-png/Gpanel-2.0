<?php
require 'config.php';
try {
    $pdo->exec("ALTER TABLE fournisseurs ADD COLUMN proxy VARCHAR(255) DEFAULT NULL AFTER mac_address");
    echo "Base de données mise à jour avec succès : Colonne proxy ajoutée !";
} catch(Exception $e) {
    echo "Erreur (la colonne existe peut-être déjà) : " . $e->getMessage();
}
?>
