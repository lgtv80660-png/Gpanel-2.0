<?php
function panel_language() {
    $lang = $_SESSION['panel_language'] ?? 'fr';
    return in_array($lang, ['fr', 'en', 'ar'], true) ? $lang : 'fr';
}
function panel_t($key) {
    $messages = [
        'sync_configuration_missing_or_invalid' => ['Synchro non configurée : vérifiez URL HTTPS et secret.', 'Sync URL or secret missing.', 'Sync configuration missing.'],
        'sync_curl_unavailable' => ['Extension cURL indisponible.', 'cURL unavailable.', 'cURL unavailable.'],
        'sync_transport_failed' => ['Backend injoignable ou délai dépassé.', 'Backend unreachable or timed out.', 'Backend unreachable.'],
        'sync_auth_rejected' => ['Secret de synchronisation refusé par le backend.', 'Backend rejected sync secret.', 'Sync authentication rejected.'],
        'sync_backend_failed' => ['Backend en erreur : synchronisation non confirmée.', 'Backend sync failed.', 'Backend sync failed.'],
        'sync_response_invalid' => ['Réponse backend invalide : synchronisation non confirmée.', 'Invalid backend acknowledgement.', 'Invalid backend response.'],
        'auth_required' => ['Connexion administrateur requise.', 'Administrator login required.', 'يجب تسجيل الدخول كمدير.'],
        'permission_denied' => ['Permission refusée.', 'Permission denied.', 'الإذن مرفوض.'],
        'csrf_invalid' => ['Session du formulaire invalide. Rechargez la page.', 'Invalid form session. Reload the page.', 'جلسة النموذج غير صالحة. أعد تحميل الصفحة.'],
        'method_not_allowed' => ['Méthode non autorisée.', 'Method not allowed.', 'طريقة الطلب غير مسموحة.'],
        'invalid_request' => ['Requête invalide.', 'Invalid request.', 'الطلب غير صالح.'],
        'preference_unavailable' => ['Enregistrement de la langue indisponible.', 'Language preference storage unavailable.', 'تعذر حفظ اللغة.'],
        'revalidation_failed' => ['Catalogue enregistré ; notification du cache non confirmée.', 'Catalog saved; cache notification unconfirmed.', 'تم حفظ الكتالوج؛ لم يتم تأكيد إشعار التخزين المؤقت.'],
        'revalidation_ok' => ['Notification du cache confirmée.', 'Cache notification confirmed.', 'تم تأكيد إشعار التخزين المؤقت.'],
        'import_failed' => ['Importation annulée ; données du lot conservées.', 'Import cancelled; batch data preserved.', 'تم إلغاء الاستيراد؛ تم الاحتفاظ ببيانات الدفعة.'],
        'import_ok' => ['Importation terminée.', 'Import completed.', 'اكتمل الاستيراد.'],
        'database_unavailable' => ['Base de données indisponible.', 'Database unavailable.', 'قاعدة البيانات غير متاحة.'],
        'import_empty' => ['Aucun bouquet sélectionné.', 'No group selected.', 'لم يتم اختيار باقة.'],
        'mac_missing' => ['Adresse MAC manquante.', 'Missing MAC address.', 'عنوان MAC مفقود.'],
        'import_started' => ['Importation SQL en cours pour', 'SQL import in progress for', 'جارٍ استيراد SQL للمصدر'],
        'processing' => ['Traitement', 'Processing', 'جارٍ المعالجة'],
        'xtream_download' => ['Téléchargement API Xtream...', 'Downloading Xtream API...', 'جارٍ التنزيل من Xtream API...'],
        'm3u_parse' => ['Analyse M3U...', 'Parsing M3U...', 'جارٍ تحليل M3U...'],
        'group_id' => ['Bouquet ID:', 'Group ID:', 'معرّف الباقة:'],
        'items_inserted' => ['éléments insérés.', 'items inserted.', 'عناصر تمت إضافتها.'],
        'sql_error' => ['Erreur SQL :', 'SQL error:', 'خطأ SQL:'],
        'back_panel' => ['Retour au Panel', 'Back to panel', 'العودة إلى اللوحة'],
        'kind_live' => ['Direct', 'Live', 'بث مباشر'],
        'kind_movie' => ['Films', 'Movies', 'أفلام'],
        'kind_series' => ['Séries', 'Series', 'مسلسلات'],
    ];
    return $messages[$key][array_search(panel_language(), ['fr', 'en', 'ar'], true)] ?? $key;
}
function panel_preference_path($id) {
    $dir = getenv('PANEL_STATE_DIR');
    $real = $dir ? realpath($dir) : false;
    $web = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: realpath(__DIR__);
    if (!$real || !is_writable($real) || strncmp(strtolower($real . DIRECTORY_SEPARATOR), strtolower($web . DIRECTORY_SEPARATOR), strlen($web . DIRECTORY_SEPARATOR)) === 0) return false;
    return $real . DIRECTORY_SEPARATOR . 'language-' . hash('sha256', (string)$id) . '.json';
}
function panel_load_language($id) {
    $path = panel_preference_path($id);
    $data = $path && is_file($path) ? json_decode(@file_get_contents($path), true) : [];
    $lang = $data['language'] ?? 'fr';
    return in_array($lang, ['fr', 'en', 'ar'], true) ? $lang : 'fr';
}
