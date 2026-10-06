<?php
require 'config.php';
try {
    // On passe stream_name en VARCHAR(1000) pour avoir une énorme marge
    $pdo->exec("ALTER TABLE streams MODIFY stream_name VARCHAR(1000)");
    
    // On sécurise au passage les autres colonnes qui pourraient bloquer
    $pdo->exec("ALTER TABLE streams MODIFY stream_icon TEXT");
    $pdo->exec("ALTER TABLE streams MODIFY direct_source TEXT");
    
    echo "Base de données réparée avec succès ! Tu peux relancer l'importation.";
} catch(Exception $e) {
    echo "Erreur : " . $e->getMessage();
}
?>
