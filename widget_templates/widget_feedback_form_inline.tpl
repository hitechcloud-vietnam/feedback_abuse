{**
 * Inline (HostBill-hosted) form template.
 *
 * Submits via fetch() to the JSON API.  The CSRF token is generated
 * server-side and carried as a hidden field; the API also accepts
 * the X-CSRF-Token header for AJAX callers.
 *
 * Renders 7 report types via a single <select>; the field list below
 * matches the user's spec exactly (Họ và tên, SĐT, Email, URL, loại,
 * nội dung, file đính kèm).  All strings are passed through
 * `{$lang|escape:'html'}` to block XSS in label re-use.
 *}

<div class="feedback-abuse-widget feedback-abuse-inline" data-embed="0">
  <h3 class="fbw-title">{$lang.module_title|escape:'html'}</h3>
  <p class="fbw-sub">{$lang.lbl_required|escape:'html'} *</p>

  <form id="fbw-form-inline" class="fbw-form" method="post" enctype="multipart/form-data"
        action="{$form_action|escape:'html'}" novalidate>

    <input type="hidden" name="_token" value="{$csrf_token|escape:'html'}">
    <input type="hidden" name="source" value="web">
    <input type="hidden" name="language" value="{$lang_code|escape:'html'}">

    {* Họ và tên *}
    <div class="fbw-row">
      <label for="fbw-name">{$lang.lbl_full_name|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-name" name="full_name" type="text" class="fbw-input" required maxlength="128"
             autocomplete="name" placeholder="{$lang.lbl_full_name|escape:'html'}">
    </div>

    {* Số điện thoại *}
    <div class="fbw-row">
      <label for="fbw-phone">{$lang.lbl_phone|escape:'html'}</label>
      <input id="fbw-phone" name="phone" type="tel" class="fbw-input" maxlength="40"
             autocomplete="tel" placeholder="{$lang.lbl_phone|escape:'html'}">
    </div>

    {* Email *}
    <div class="fbw-row">
      <label for="fbw-email">{$lang.lbl_email|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-email" name="email" type="email" class="fbw-input" required maxlength="190"
             autocomplete="email" placeholder="{$lang.lbl_phone|escape:'html'}">
    </div>

    {* URL / Website / Tên miền *}
    <div class="fbw-row">
      <label for="fbw-url">{$lang.lbl_url|escape:'html'} <span class="req">*</span></label>
      <input id="fbw-url" name="url" type="text" class="fbw-input" required maxlength="2048"
             placeholder="{$lang.lbl_url|escape:'html'}" inputmode="url">
    </div>

    {* Loại báo cáo lạm dụng *}
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

    {* Nội dung báo cáo *}
    <div class="fbw-row">
      <label for="fbw-message">{$lang.lbl_message|escape:'html'} <span class="req">*</span></label>
      <textarea id="fbw-message" name="message" class="fbw-input" required rows="6"
                maxlength="10000"
                placeholder="{$lang.lbl_message|escape:'html'}"></textarea>
    </div>

    {if $allow_attachments}
      {* File đính kèm *}
      <div class="fbw-row">
        <label for="fbw-files">{$lang.lbl_attachments|escape:'html'}
          <span class="opt">({$lang.lbl_optional|escape:'html'})</span>
        </label>
        <input id="fbw-files" name="attachments[]" type="file" class="fbw-file" multiple
               accept=".{foreach from=$allowed_exts item=e name=ae}{if !$smarty.foreach.ae.first},{/if}{$e|escape:'html'}{/foreach}">
        <small class="fbw-hint">
          {$lang.lbl_attachments|escape:'html'}: .{$allowed_exts|@implode:', .'|escape:'html'} (max {$max_file_size_mb|escape:'html'} MB)
        </small>
      </div>
    {/if}

    {if $require_captcha}
      <div class="fbw-row">
        <label>CAPTCHA <span class="req">*</span></label>
        <div class="fbw-captcha" data-fbw-captcha></div>
      </div>
    {/if}

    <div class="fbw-row fbw-actions">
      <button type="submit" class="fbw-submit" data-fbw-submit>
        <span class="fbw-submit-idle">{$lang.lbl_submit|escape:'html'}</span>
        <span class="fbw-submit-busy" hidden>{$lang.lbl_submitting|escape:'html'}</span>
      </button>
    </div>

    <div class="fbw-result" data-fbw-result hidden></div>
  </form>
</div>

<style>
  .feedback-abuse-inline { max-width: 720px; margin: 0 auto; font-family: inherit; }
  .feedback-abuse-inline .fbw-title { margin: 0 0 4px; font-size: 1.4em; }
  .feedback-abuse-inline .fbw-sub   { color: #777; margin: 0 0 16px; font-size: 0.9em; }
  .feedback-abuse-inline .fbw-row   { margin-bottom: 12px; }
  .feedback-abuse-inline .fbw-row label { display: block; font-weight: 600; margin-bottom: 4px; }
  .feedback-abuse-inline .req { color: #d00; }
  .feedback-abuse-inline .opt { color: #888; font-weight: 400; }
  .feedback-abuse-inline .fbw-input,
  .feedback-abuse-inline .fbw-file {
    width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font: inherit;
  }
  .feedback-abuse-inline .fbw-hint { display: block; color: #888; font-size: 0.85em; margin-top: 4px; }
  .feedback-abuse-inline .fbw-submit {
    background: #0066cc; color: #fff; border: 0; padding: 10px 24px; border-radius: 4px; cursor: pointer; font: inherit;
  }
  .feedback-abuse-inline .fbw-submit:disabled { opacity: 0.6; cursor: not-allowed; }
  .feedback-abuse-inline .fbw-result { margin-top: 16px; padding: 12px; border-radius: 4px; }
  .feedback-abuse-inline .fbw-result.ok    { background: #e9f7ec; color: #186a2b; }
  .feedback-abuse-inline .fbw-result.error { background: #fdecea; color: #b3261e; }
</style>

<script>
(function () {
  'use strict';
  var form = document.getElementById('fbw-form-inline');
  if (!form) { return; }
  var result = form.querySelector('[data-fbw-result]');
  var submit = form.querySelector('[data-fbw-submit]');
  var idle   = submit.querySelector('.fbw-submit-idle');
  var busy   = submit.querySelector('.fbw-submit-busy');
  var submitUrl = {jsonencode($submit_url)};

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
                 'X-Embed-Token': form.dataset.embed === '1'
                     ? (form.querySelector('input[name=embed_token]') || {}).value || ''
                     : '',
                 'X-Embed-Origin': form.dataset.embed === '1'
                     ? (form.querySelector('input[name=embed_origin]') || {}).value || ''
                     : '' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        submit.disabled = false;
        idle.hidden = false; busy.hidden = true;
        if (out.ok && out.body && out.body.ok) {
          result.className = 'fbw-result ok';
          result.textContent = '{$lang.lbl_thank_you|escape:'javascript'} (ID: ' + (out.body.public_id || '') + ')';
          form.reset();
        } else {
          var msg = (out.body && (out.body.error_message || out.body.error)) || 'submit_failed';
          result.className = 'fbw-result error';
          result.textContent = msg;
        }
        result.hidden = false;
      })
      .catch(function () {
        submit.disabled = false;
        idle.hidden = false; busy.hidden = true;
        result.className = 'fbw-result error';
        result.textContent = 'submit_failed';
        result.hidden = false;
      });
  });
})();
</script>
