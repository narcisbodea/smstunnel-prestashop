{**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel" id="smstunnel-log">
  <div class="panel-heading">
    <i class="icon-list"></i> {l s='Recent texts' d='Modules.Smstunnel.Admin'}
  </div>
  {if $smstunnel_log}
    <div class="table-responsive">
      <table class="table smstunnel-log">
        <thead>
          <tr>
            <th>{l s='Date' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='Text' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='Order' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='Number' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='Status' d='Modules.Smstunnel.Admin'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $smstunnel_log as $row}
            <tr>
              <td class="smstunnel-nowrap">{$row.date|escape:'html':'UTF-8'}</td>
              <td>
                <strong>{$row.what|escape:'html':'UTF-8'}</strong>
                <div class="smstunnel-message">{$row.message|escape:'html':'UTF-8'|nl2br}</div>
              </td>
              <td class="smstunnel-nowrap">
                {if $row.order_url}
                  <a href="{$row.order_url|escape:'html':'UTF-8'}">{$row.order|escape:'html':'UTF-8'}</a>
                {else}
                  {$row.order|escape:'html':'UTF-8'}
                {/if}
              </td>
              <td class="smstunnel-nowrap">{$row.recipient|escape:'html':'UTF-8'}</td>
              <td>
                {if $row.status == 'sent'}
                  <span class="label label-success">{$row.status_label|escape:'html':'UTF-8'}</span>
                {elseif $row.status == 'queued'}
                  <span class="label label-warning">{$row.status_label|escape:'html':'UTF-8'}</span>
                {elseif $row.status == 'failed'}
                  <span class="label label-danger">{$row.status_label|escape:'html':'UTF-8'}</span>
                {else}
                  <span class="label label-default">{$row.status_label|escape:'html':'UTF-8'}</span>
                {/if}
                {if $row.detail}
                  <div class="smstunnel-detail">{$row.detail|escape:'html':'UTF-8'}</div>
                {/if}
                {if $row.attempts > 1}
                  <div class="smstunnel-detail">{l s='Tried %d times' sprintf=[$row.attempts] d='Modules.Smstunnel.Admin'}</div>
                {/if}
                {if $row.status == 'failed'}
                  <form method="post" action="{$smstunnel_action|escape:'html':'UTF-8'}" class="smstunnel-resend">
                    <input type="hidden" name="id_smstunnel_log" value="{$row.id|intval}">
                    <button type="submit" class="btn btn-default btn-xs" name="submitSmstunnelResend" value="1">
                      <i class="icon-refresh"></i> {l s='Send again' d='Modules.Smstunnel.Admin'}
                    </button>
                  </form>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p>{l s='No texts yet. They show up here as orders come in.' d='Modules.Smstunnel.Admin'}</p>
  {/if}
  <p class="help-block">{l s='This log keeps the last 1000 texts. Uninstalling the module deletes it.' d='Modules.Smstunnel.Admin'}</p>
</div>
