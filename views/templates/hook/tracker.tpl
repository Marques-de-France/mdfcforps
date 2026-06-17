{**
 * Marques de France — front tracker injection (hooked on displayBeforeBodyClosingTag)
 *}
{*
 * 2007-2026 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.
 *}

<script>
window.mdfcforpsRuntime = {
  ajaxUrl: "{$mdfcforps_ajax_url|escape:'javascript':'UTF-8'}"
};
</script>
<script src="{$mdfcforps_js_url|escape:'html':'UTF-8'}?v={$mdfcforps_version|escape:'html':'UTF-8'}" defer></script>
