{**
 * Client-area template — used by the user-side controller.
 *}

<div class="feedback-abuse-user">
  {if $disabled}
    <p>{$lang.err_module_disabled|escape:'html'}</p>
  {else}
    <h2>{$lang.module_title|escape:'html'}</h2>

    <h3>{$lang.lbl_submit|escape:'html'}</h3>
    <form method="post" enctype="multipart/form-data" action="{$submit_url|escape:'html'}" novalidate>
      <input type="hidden" name="_token" value="{$csrf_token|escape:'html'}">
      <input type="hidden" name="source" value="client_area">
      <input type="hidden" name="language" value="english">

      <p>
        <label>{$lang.lbl_full_name|escape:'html'}</label><br>
        <input type="text" name="full_name" class="form-control" maxlength="128">
      </p>
      <p>
        <label>{$lang.lbl_email|escape:'html'}</label><br>
        <input type="email" name="email" class="form-control" required maxlength="190">
      </p>
      <p>
        <label>{$lang.lbl_phone|escape:'html'}</label><br>
        <input type="tel" name="phone" class="form-control" maxlength="40">
      </p>
      <p>
        <label>{$lang.lbl_url|escape:'html'}</label><br>
        <input type="text" name="url" class="form-control" maxlength="2048" required>
      </p>
      <p>
        <label>{$lang.lbl_report_type|escape:'html'}</label><br>
        <select name="type" class="form-control" required>
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
      </p>
      <p>
        <label>{$lang.lbl_message|escape:'html'}</label><br>
        <textarea name="message" class="form-control" rows="6" required maxlength="10000"></textarea>
      </p>
      {if $allow_attachments}
        <p>
          <label>{$lang.lbl_attachments|escape:'html'}</label><br>
          <input type="file" name="attachments[]" multiple
                 accept=".{foreach from=$allowed_exts item=e name=ae}{if !$smarty.foreach.ae.first},{/if}{$e|escape:'html'}{/foreach}">
          <br><small>.{$allowed_exts|@implode:', .'|escape:'html'} (max {$max_file_size_mb|escape:'html'} MB)</small>
        </p>
      {/if}
      <p>
        <button type="submit" class="btn btn-primary">{$lang.lbl_submit|escape:'html'}</button>
      </p>
    </form>

    <h3>{$lang.menu_reports|escape:'html'}</h3>
    {if $items|@count == 0}
      <p>{$lang.lbl_no_reports|escape:'html'}</p>
    {else}
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>{$lang.lbl_ticket_id|escape:'html'}</th>
            <th>{$lang.lbl_report_type|escape:'html'}</th>
            <th>{$lang.lbl_status|escape:'html'}</th>
            <th>{$lang.lbl_severity|escape:'html'}</th>
            <th>{$lang.lbl_created|escape:'html'}</th>
            <th>{$lang.lbl_updated|escape:'html'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$items item=r}
            <tr>
              <td>{$r->public_id|escape:'html'}</td>
              <td>{$r->type|escape:'html'}</td>
              <td>
                {if $r->status == 'new'}{$lang.st_new|escape:'html'}
                {elseif $r->status == 'triaged'}{$lang.st_triaged|escape:'html'}
                {elseif $r->status == 'investigating'}{$lang.st_investigating|escape:'html'}
                {elseif $r->status == 'action_taken'}{$lang.st_action_taken|escape:'html'}
                {elseif $r->status == 'rejected'}{$lang.st_rejected|escape:'html'}
                {elseif $r->status == 'closed'}{$lang.st_closed|escape:'html'}
                {else}{$r->status|escape:'html'}{/if}
              </td>
              <td>{$r->severity|escape:'html'}</td>
              <td>{$r->submitted_at|escape:'html'}</td>
              <td>{$r->updated_at|escape:'html'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  {/if}
</div>
