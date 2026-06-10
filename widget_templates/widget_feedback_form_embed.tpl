{**
 * Embeddable form template (rendered inside the 3rd-party iframe).
 *
 * Differences from the inline template:
 *   - All assets are loaded from the same HostBill origin via relative URLs
 *   - The form action is relative so the iframe stays in the parent's origin
 *   - The submit URL is absolute so the JS fetcher can cross-post
 *   - extra hidden fields: embed_token, embed_origin
 *}

<!DOCTYPE html>
<html lang="{$lang_code|escape:'html'}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$lang.module_title|escape:'html'}</title>
<style>
  html, body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fafafa; color: #222; }
  .fbw-embed { padding: 16px; box-sizing: border-box; }
  .fbw-embed .fbw-title { margin: 0 0 4px; font-size: 1.25em; }
  .fbw-embed .fbw-sub   { color: #777; margin: 0 0 14px; font-size: 0.85em; }
  .fbw-embed .fbw-row   { margin-bottom: 10px; }
  .fbw-embed .fbw-row label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9em; }
  .fbw-embed .req { color: #d00; }
  .fbw-embed .opt { color: #888; font-weight: 400; }
  .fbw-embed .fbw-input,
  .fbw-embed .fbw-file {
    width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #bbb; border-radius: 3px; font: inherit; font-size: 0.95em;
  }
  .fbw-embed textarea.fbw-input { min-height: 110px; resize: vertical; }
  .fbw-embed .fbw-hint { display: block; color: #888; font-size: 0.78em; margin-top: 3px; }
  .fbw-embed .fbw-submit {
    background: #0066cc; color: #fff; border: 0; padding: 8px 18px; border-radius: 3px; cursor: pointer; font: inherit; font-size: 0.95em;
  }
  .fbw-embed .fbw-submit:disabled { opacity: 0.6; cursor: not-allowed; }
  .fbw-embed .fbw-result { margin-top: 12px; padding: 10px; border-radius: 3px; font-size: 0.9em; }
  .fbw-embed .fbw-result.ok    { background: #e9f7ec; color: #186a2b; }
  .fbw-embed .fbw-result.error { background: #fdecea; color: #b3261e; }
  .fbw-embed .fbw-branding { margin-top: 12px; text-align: right; font-size: 0.75em; color: #999; }
</style>
</head>
<body>
<div class="fbw-embed">
  <h3 class="fbw-title">{$lang.module_title|escape:'html'}</h3>
  <p class="fbw-sub">{$lang.lbl_required|escape:'html'} *</p>

  <form id="fbw-form-embed" data-embed="1" class="fbw-form" method="post" enctype="multipart/form-data"
        action="{$form_action|escape:'html'}" novalidate>

    <input type="hidden" name="_token" value="{$csrf_token|escape:'html'}">
    <input type="hidden" name="source" value="embed">
    <input type="hidden" name="language" value="{$lang_code|escape:'html'}">
    <input type="hidden" name="embed_token" value="{$embed_token|escape:'html'}">
    <input type="hidden" name="embed_origin" value="{$embed_origin|escape:'html'}">

    <div class="fbw-row">
      <label for="fbw-name">{$lang.lbl_full_name|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-name" name="full_name" type="text" class="fbw-input" required maxlength="128" autocomplete="name">
    </div>
    <div class="fbw-row">
      <label for="fbw-phone">{$lang.lbl_phone|escape:'html'}</label>
      <input id="fbw-phone" name="phone" type="tel" class="fbw-input" maxlength="40" autocomplete="tel">
    </div>
    <div class="fbw-row">
      <label for="fbw-email">{$lang.lbl_email|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-email" name="email" type="email" class="fbw-input" required maxlength="190" autocomplete="email">
    </div>
    <div class="fbw-row">
      <label for="fbw-url">{$lang.lbl_url|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-url" name="url" type="text" class="fbw-input" required maxlength="2048" inputmode="url">
    </div>
    <div class="fbw-row">
      <label for="fbw-type">{$lang.lbl_report_type|escape:'html'} <span class="req">*</span></label>
      <select id="fbw-type" name="type" class="fbw-input" required>
        <option value="">-- {$lang.lbl_report_type|escape:'html'} --</option>
        {foreach from=$enabled_types item=t}
          <option value="{$t|escape:'html'}">
            {if $t == 'feedback'}{$lang.type_feedback|escape:'html'}
            {elseif $t == 'phishing'}{$lang.type_phishing|escape:'html'}
            {elseif $t == 'malware'}{$lang.type_malware|escape:'html'}
            {elseif $t == 'botnet'}{$lang.type_botnet|escape:'html'}
            {elseif $t == 'spam'}{$lang.type_spam|escape:'html'}
            {elseif $t == 'domain_abuse'}{$lang.type_domain_abuse|escape:'html'}
            {elseif $t == 'network_abuse'}{$lang.type_network_abuse|escape:'html'}
            {else}{$t|escape:'html'}{/if}
          </option>
        {/foreach}
      </select>
    </div>
    <div class="fbw-row">
      <label for="fbw-message">{$lang.lbl_message|escape:'html'} <span class="req">*</span></label>
      <textarea id="fbw-message" name="message" class="fbw-input" required rows="5" maxlength="10000"></textarea>
    </div>

    {if $allow_attachments}
      <div class="fbw-row">
        <label for="fbw-files">{$lang.lbl_attachments|escape:'html'}
          <span class="opt">({$lang.lbl_optional|escape:'html'})</span>
        </label>
        <input id="fbw-files" name="attachments[]" type="file" class="fbw-file" multiple
               accept=".{foreach from=$allowed_exts item=e name=ae}{if !$smarty.foreach.ae.first},{/if}{$e|escape:'html'}{/foreach}">
        <small class="fbw-hint">.{$allowed_exts|@implode:', .'|escape:'html'} (max {$max_file_size_mb|escape:'html'} MB)</small>
      </div>
    {/if}

    {if $require_captcha}
      <div class="fbw-row">
        <label>CAPTCHA <span class="req">*</span></label>
        <div class="fbw-captcha" data-fbw-captcha></div>
      </div>
    {/if}

    <div class="fbw-row">
      <button type="submit" class="fbw-submit" data-fbw-submit>
        <span class="fbw-submit-idle">{$lang.lbl_submit|escape:'html'}</span>
        <span class="fbw-submit-busy" hidden>{$lang.lbl_submitting|escape:'html'}</span>
      </button>
    </div>
    <div class="fbw-result" data-fbw-result hidden></div>
  </form>

  <div class="fbw-branding">Powered by HostBill · Feedback & Abuse</div>
</div>

{literal}
<script>
(function () {
  'use strict';
  var form = document.getElementById('fbw-form-embed');
  if (!form) { return; }
  var result = form.querySelector('[data-fbw-result]');
  var submit = form.querySelector('[data-fbw-submit]');
  var idle   = submit.querySelector('.fbw-submit-idle');
  var busy   = submit.querySelector('.fbw-submit-busy');
  var submitUrl = {/literal}{jsonencode($submit_url)}{literal};

  // Post a height message to the parent so the iframe can resize.
  function postHeight() {
    try {
      var h = document.body.scrollHeight;
      parent.postMessage({ fbw: 'resize', h: h }, '*');
    } catch (e) {}
  }
  postHeight();
  window.addEventListener('load', postHeight);
  window.addEventListener('resize', postHeight);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (submit.disabled) { return; }
    submit.disabled = true;
    idle.hidden = true; busy.hidden = false;
    result.hidden = true; result.className = 'fbw-result'; result.textContent = '';

    var fd = new FormData(form);
    fetch(submitUrl, {
      method:  'POST',
      body:    fd,
      headers: { 'X-CSRF-Token': form.querySelector('input[name=_token]').value,
                 'X-Embed-Token': form.querySelector('input[name=embed_token]').value,
                 'X-Embed-Origin': form.querySelector('input[name=embed_origin]').value },
      credentials: 'omit'
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        submit.disabled = false;
        idle.hidden = false; busy.hidden = true;
        if (out.ok && out.body && out.body.ok) {
          result.className = 'fbw-result ok';
          result.textContent = 'Thank you. Your report has been received. (ID: ' + (out.body.public_id || '') + ')';
          try { parent.postMessage({ fbw: 'submitted', public_id: out.body.public_id }, '*'); } catch (e) {}
          form.reset();
        } else {
          var msg = (out.body && (out.body.error_message || out.body.error)) || 'submit_failed';
          result.className = 'fbw-result error';
          result.textContent = msg;
        }
        result.hidden = false;
        postHeight();
      })
      .catch(function () {
        submit.disabled = false;
        idle.hidden = false; busy.hidden = true;
        result.className = 'fbw-result error';
        result.textContent = 'submit_failed';
        result.hidden = false;
        postHeight();
      });
  });
})();
</script>
{/literal}
</body>
</html>
