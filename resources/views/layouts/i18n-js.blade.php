@php
    $i18nLocale = app()->getLocale();
    $i18nFile = lang_path($i18nLocale . '.json');
    // English is the text in the code, so only another language needs the phrases
    $i18nLines = ($i18nLocale !== 'en' && is_file($i18nFile)) ? (json_decode((string) file_get_contents($i18nFile), true) ?: []) : [];
@endphp
<script>
    window.I18N = @json($i18nLines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT);
    // __('Checkout failed')   or   __('Grade :grade', {grade: 3})
    window.__ = function (key, replace) {
        var text = (window.I18N && window.I18N[key]) || key;
        if (replace) {
            Object.keys(replace).forEach(function (name) { text = text.split(':' + name).join(replace[name]); });
        }
        return text;
    };
</script>
