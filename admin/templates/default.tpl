{**
 * Admin template — Feedback & Abuse Reports module.
 *
 * The controller sets $active_view in ['list', 'view', 'tokens', 'settings'].
 * We render the corresponding sub-section inside this single .tpl so
 * HostBill's default.tpl resolution stays simple.
 *
 * Variables:
 *   $lang              — module language array (english|vietnamese)
 *   $moduleurl, $modulename, $modname, $moduleid
 *   $active_view
 *   $items, $total, $page, $pages
 *   $counts_by_status, $counts_by_type
 *   $type, $status, $q   — current filters
 *   $report, $attachments, $notes, $audits, $admins   (view)
 *   $tokens, $issued_secret, $issued_token_id         (tokens)
 *   $enabled_types
 *   $admin_id
 *}

<div class="feedback-abuse-wrap">
  <ul class="nav nav-tabs" style="margin-bottom: 20px;">
    <li class="{if $active_view == 'list'}active{/if}">
      <a href="{$moduleurl}?cmd={$modname}">{$lang.menu_reports|escape:'html'}</a>
    </li>
    <li class="{if $active_view == 'tokens'}active{/if}">
      <a href="{$moduleurl}?cmd={$modname}&action=tokens">{$lang.menu_attachments|escape:'html'}</a>
    </li>
    <li class="{if $active_view == 'settings'}active{/if}">
      <a href="{$moduleurl}?cmd={$modname}&action=settings">{$lang.menu_settings|escape:'html'}</a>
    </li>
  </ul>

  {* ============================================================ *}
  {* LIST VIEW                                                    *}
  {* ============================================================ *}
  {if $active_view == 'list'}

    <form method="get" class="form-inline" style="margin-bottom: 12px;">
      <input type="hidden" name="cmd" value="{$modname}">
      <select name="type" class="form-control input-sm" style="margin-right:6px;">
        <option value="all">{$lang.lbl_filter_all|escape:'html'}</option>
        {foreach from=$enabled_types item=t}
          <option value="{$t|escape:'html'}" {if $type == $t}selected{/if}>
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
      <select name="status" class="form-control input-sm" style="margin-right:6px;">
        <option value="all"  {if $status == 'all'}selected{/if}>{$lang.lbl_filter_all|escape:'html'}</option>
        <option value="new"  {if $status == 'new'}selected{/if}>{$lang.st_new|escape:'html'}</option>
        <option value="open" {if $status == 'open'}selected{/if}>{$lang.lbl_filter_open|escape:'html'}</option>
        <option value="triaged"        {if $status == 'triaged'}selected{/if}>{$lang.st_triaged|escape:'html'}</option>
        <option value="investigating"  {if $status == 'investigating'}selected{/if}>{$lang.st_investigating|escape:'html'}</option>
        <option value="action_taken"   {if $status == 'action_taken'}selected{/if}>{$lang.st_action_taken|escape:'html'}</option>
        <option value="rejected"       {if $status == 'rejected'}selected{/if}>{$lang.st_rejected|escape:'html'}</option>
        <option value="closed"         {if $status == 'closed'}selected{/if}>{$lang.st_closed|escape:'html'}</option>
      </select>
      <input type="text" name="q" class="form-control input-sm" value="{$q|escape:'html'}"
             placeholder="{$lang.lbl_search|escape:'html'}" style="margin-right:6px; min-width:220px;">
      <button class="btn btn-sm btn-primary" type="submit">{$lang.lbl_search|escape:'html'}</button>
      <a class="btn btn-sm btn-default" href="{$moduleurl}?cmd={$modname}&action=export&type={$type|escape:'html'}&status={$status|escape:'html'}&q={$q|escape:'html'}">
        {$lang.btn_export_csv|escape:'html'}
      </a>
    </form>

    <div class="row" style="margin-bottom: 12px;">
      <div class="col-md-12">
        <span class="label label-default">{$lang.lbl_filter_all|escape:'html'}: {$total|escape:'html'}</span>
        {foreach from=$counts_by_status key=k item=c}
          <span class="label label-info" style="margin-left:6px;">{$k|escape:'html'}: {$c|escape:'html'}</span>
        {/foreach}
      </div>
    </div>

    <table class="table table-bordered table-striped table-hover">
      <thead>
        <tr>
          <th>{$lang.lbl_ticket_id|escape:'html'}</th>
          <th>{$lang.lbl_report_type|escape:'html'}</th>
          <th>{$lang.lbl_status|escape:'html'}</th>
          <th>{$lang.lbl_severity|escape:'html'}</th>
          <th>{$lang.lbl_reporter|escape:'html'}</th>
          <th>{$lang.lbl_url|escape:'html'}</th>
          <th>{$lang.lbl_created|escape:'html'}</th>
          <th>{$lang.lbl_actions|escape:'html'}</th>
        </tr>
      </thead>
      <tbody>
        {if $items|@count == 0}
          <tr><td colspan="8" style="text-align:center; color:#999;">{$lang.lbl_no_reports|escape:'html'}</td></tr>
        {/if}
        {foreach from=$items item=r}
          <tr>
            <td><a href="{$moduleurl}?cmd={$modname}&action=view&id={$r->id|escape:'html'}">{$r->public_id|escape:'html'}</a></td>
            <td>{$r->type|escape:'html'}</td>
            <td><span class="label {if $r->status == 'new'}label-danger{elseif $r->status == 'closed'}label-default{else}label-warning{/if}">
              {if $r->status == 'new'}{$lang.st_new|escape:'html'}
              {elseif $r->status == 'triaged'}{$lang.st_triaged|escape:'html'}
              {elseif $r->status == 'investigating'}{$lang.st_investigating|escape:'html'}
              {elseif $r->status == 'action_taken'}{$lang.st_action_taken|escape:'html'}
              {elseif $r->status == 'rejected'}{$lang.st_rejected|escape:'html'}
              {elseif $r->status == 'closed'}{$lang.st_closed|escape:'html'}
              {else}{$r->status|escape:'html'}{/if}
            </span></td>
            <td>{$r->severity|escape:'html'}</td>
            <td>{$r->full_name|escape:'html'}<br><small>{$r->email|escape:'html'}</small></td>
            <td>{$r->url|escape:'html'}</td>
            <td>{$r->submitted_at|escape:'html'}</td>
            <td><a class="btn btn-xs btn-primary" href="{$moduleurl}?cmd={$modname}&action=view&id={$r->id|escape:'html'}">{$lang.lbl_actions|escape:'html'}</a></td>
          </tr>
        {/foreach}
      </tbody>
    </table>

    {if $pages > 1}
      <nav>
        <ul class="pagination">
          {for $p=1 to $pages}
            <li class="{if $p == $page}active{/if}">
              <a href="{$moduleurl}?cmd={$modname}&type={$type|escape:'html'}&status={$status|escape:'html'}&q={$q|escape:'html'}&p={$p|escape:'html'}">{$p|escape:'html'}</a>
            </li>
          {/for}
        </ul>
      </nav>
    {/if}

  {* ============================================================ *}
  {* SINGLE REPORT VIEW                                          *}
  {* ============================================================ *}
  {elseif $active_view == 'view'}

    <h3>{$lang.lbl_ticket_id|escape:'html'}: {$report->public_id|escape:'html'}
      <small style="margin-left:8px;">[{$report->type|escape:'html'} / {$report->severity|escape:'html'}]</small>
    </h3>

    <table class="table table-bordered" style="max-width:900px;">
      <tr>
        <th style="width:160px;">{$lang.lbl_status|escape:'html'}</th>
        <td>
          <form method="post" action="{$moduleurl}?cmd={$modname}&action=status&id={$report->id|escape:'html'}" style="display:inline-block;">
            <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
            <select name="to" class="form-control input-sm" style="display:inline-block; width:auto;">
              <option value="new"            {if $report->status == 'new'}selected{/if}>{$lang.st_new|escape:'html'}</option>
              <option value="triaged"        {if $report->status == 'triaged'}selected{/if}>{$lang.st_triaged|escape:'html'}</option>
              <option value="investigating"  {if $report->status == 'investigating'}selected{/if}>{$lang.st_investigating|escape:'html'}</option>
              <option value="action_taken"   {if $report->status == 'action_taken'}selected{/if}>{$lang.st_action_taken|escape:'html'}</option>
              <option value="rejected"       {if $report->status == 'rejected'}selected{/if}>{$lang.st_rejected|escape:'html'}</option>
              <option value="closed"         {if $report->status == 'closed'}selected{/if}>{$lang.st_closed|escape:'html'}</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{$lang.btn_save|escape:'html'}</button>
          </form>
        </td>
      </tr>
      <tr><th>{$lang.lbl_assigned_to|escape:'html'}</th>
        <td>
          <form method="post" action="{$moduleurl}?cmd={$modname}&action=assign&id={$report->id|escape:'html'}" style="display:inline-block;">
            <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
            <select name="aid" class="form-control input-sm" style="display:inline-block; width:auto;">
              {foreach from=$admins item=a}
                <option value="{$a.id|escape:'html'}" {if $report->admin_id == $a.id}selected{/if}>{$a.login|escape:'html'}</option>
              {/foreach}
            </select>
            <button class="btn btn-sm btn-primary" type="submit">{$lang.btn_save|escape:'html'}</button>
          </form>
        </td>
      </tr>
      <tr><th>{$lang.lbl_full_name|escape:'html'}</th><td>{$report->full_name|escape:'html'}</td></tr>
      <tr><th>{$lang.lbl_email|escape:'html'}</th><td>{$report->email|escape:'html'}</td></tr>
      <tr><th>{$lang.lbl_phone|escape:'html'}</th><td>{$report->phone|escape:'html'}</td></tr>
      <tr><th>{$lang.lbl_url|escape:'html'}</th><td>{$report->url|escape:'html'}</td></tr>
      <tr><th>{$lang.lbl_message|escape:'html'}</th><td><pre style="white-space:pre-wrap; word-break:break-word;">{$report->message|escape:'html'}</pre></td></tr>
      <tr><th>{$lang.lbl_created|escape:'html'}</th><td>{$report->submitted_at|escape:'html'}</td></tr>
      <tr><th>{$lang.lbl_updated|escape:'html'}</th><td>{$report->updated_at|escape:'html'}</td></tr>
    </table>

    <h4>{$lang.btn_view_attachments|escape:'html'} ({$attachments|@count})</h4>
    {if $attachments|@count > 0}
      <ul>
        {foreach from=$attachments item=a}
          <li>
            <a href="{$moduleurl}?cmd={$modname}&action=download&aid={$a->id|escape:'html'}">
              {$a->orig_name|escape:'html'}
            </a>
            <small>({$a->size_bytes|escape:'html'} bytes, {$a->extension|escape:'html'})</small>
          </li>
        {/foreach}
      </ul>
    {/if}

    <h4>{$lang.btn_add_note|escape:'html'}</h4>
    <form method="post" action="{$moduleurl}?cmd={$modname}&action=addnote&id={$report->id|escape:'html'}">
      <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
      <textarea name="note" rows="3" class="form-control" style="max-width:900px;"></textarea>
      <button class="btn btn-primary btn-sm" type="submit" style="margin-top:6px;">{$lang.btn_save|escape:'html'}</button>
    </form>

    {if $notes|@count > 0}
      <h4 style="margin-top:18px;">{$lang.btn_add_note|escape:'html'}</h4>
      {foreach from=$notes item=n}
        <div style="border-left:3px solid #eee; padding:6px 12px; margin-bottom:6px; max-width:900px;">
          <small>{$n->created_at|escape:'html'} — admin #{$n->admin_id|escape:'html'}</small>
          <div>{$n->note|escape:'html'}</div>
        </div>
      {/foreach}
    {/if}

    {if $audits|@count > 0}
      <h4 style="margin-top:18px;">Audit log</h4>
      <table class="table table-condensed" style="max-width:900px;">
        {foreach from=$audits item=a}
          <tr>
            <td style="width:160px;"><small>{$a->created_at|escape:'html'}</small></td>
            <td><small>{$a->actor_type|escape:'html'}:{$a->actor_id|escape:'html'} — {$a->action|escape:'html'} {if $a->from_value}({$a->from_value|escape:'html'} → {$a->to_value|escape:'html'}){/if}</small></td>
          </tr>
        {/foreach}
      </table>
    {/if}

    <form method="post" action="{$moduleurl}?cmd={$modname}&action=delete&id={$report->id|escape:'html'}"
          onsubmit="return confirm('Delete this report?');" style="margin-top:20px;">
      <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
      <button class="btn btn-sm btn-danger" type="submit">{$lang.btn_delete|escape:'html'}</button>
      <a class="btn btn-sm btn-default" href="{$moduleurl}?cmd={$modname}">{$lang.btn_back|escape:'html'}</a>
    </form>

  {* ============================================================ *}
  {* EMBED TOKENS                                                *}
  {* ============================================================ *}
  {elseif $active_view == 'tokens'}

    {if $issued_secret}
      <div class="alert alert-success">
        <strong>{$lang.msg_embed_token_issued|escape:'html'}</strong><br>
        Token ID: <code>{$issued_token_id|escape:'html'}</code><br>
        Secret (shown ONCE — copy now): <code>{$issued_secret|escape:'html'}</code>
      </div>
    {/if}

    <h3>Issue new embed token</h3>
    <form method="post" action="{$moduleurl}?cmd={$modname}&action=tokens_issue" class="form-inline" style="margin-bottom:18px;">
      <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
      <input type="text" name="origin_domain" class="form-control input-sm" placeholder="widget.example.com" required style="margin-right:6px;">
      <input type="text" name="label" class="form-control input-sm" placeholder="Label" style="margin-right:6px;">
      <input type="number" name="ttl" class="form-control input-sm" value="86400" min="60" max="31536000" style="margin-right:6px; width:120px;">
      <button class="btn btn-sm btn-primary" type="submit">{$lang.msg_embed_token_issued|escape:'html'}</button>
    </form>

    <h3>Active tokens</h3>
    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>Token ID</th>
          <th>Origin</th>
          <th>Label</th>
          <th>Issued</th>
          <th>Expires</th>
          <th>Last used</th>
          <th>Status</th>
          <th>{$lang.lbl_actions|escape:'html'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$tokens item=t}
          <tr>
            <td><code>{$t->token_id|escape:'html'}</code></td>
            <td>{$t->origin_domain|escape:'html'}</td>
            <td>{$t->label|escape:'html'}</td>
            <td>{$t->issued_at|escape:'html'}</td>
            <td>{$t->expires_at|escape:'html'}</td>
            <td>{$t->last_used_at|escape:'html'}</td>
            <td>
              {if $t->revoked_at}
                <span class="label label-default">Revoked</span>
              {elseif $t->expires_at && $t->expires_at|strtotime < $smarty.now}
                <span class="label label-warning">Expired</span>
              {else}
                <span class="label label-success">Active</span>
              {/if}
            </td>
            <td>
              {if !$t->revoked_at}
                <form method="post" action="{$moduleurl}?cmd={$modname}&action=tokens_revoke&id={$t->token_id|escape:'html'}" style="display:inline;">
                  <input type="hidden" name="_token" value="{$smarty.session.csrf_token|default:''|escape:'html'}">
                  <button class="btn btn-xs btn-danger" type="submit">{$lang.btn_delete|escape:'html'}</button>
                </form>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>

  {* ============================================================ *}
  {* SETTINGS — proxy to HostBill core module-config page        *}
  {* ============================================================ *}
  {elseif $active_view == 'settings'}

    <p>Module configuration is managed through HostBill's standard
       <em>Settings → Modules → Feedback &amp; Abuse Reports</em> page.
       The widget form, embed token issuance, and notification addresses
       can all be tuned there.</p>

  {/if}
</div>
