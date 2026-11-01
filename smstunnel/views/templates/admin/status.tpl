{**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel smstunnel-panel" id="smstunnel-status">
  <div class="panel-heading">
    <i class="icon-mobile-phone"></i> {l s='SMSTunnel: texts from your own phone' d='Modules.Smstunnel.Admin'}
  </div>

  <p>{l s='Your shop hands each text to your SMSTunnel account, and your Android phone sends it from its own SIM. Customers see your number and can reply to it.' d='Modules.Smstunnel.Admin'}</p>

  {if $smstunnel.test_server}
    <div class="alert alert-warning">
      {l s='Test mode: texts go to this test server, not to smstunnel.io. Change it under Advanced.' d='Modules.Smstunnel.Admin'}
      <code>{$smstunnel.test_server|escape:'html':'UTF-8'}</code>
    </div>
  {/if}

  {if !$smstunnel.has_key}
    <h4>{l s='Get started in three steps' d='Modules.Smstunnel.Admin'}</h4>
    <ol class="smstunnel-steps">
      <li>
        {l s='Install the SMSTunnel app on your Android phone and sign in.' d='Modules.Smstunnel.Admin'}
        <a href="{$smstunnel.google_play_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Get it on Google Play' d='Modules.Smstunnel.Admin'}</a>
      </li>
      <li>
        {l s='In your SMSTunnel dashboard, open Apps, then Add an app, and copy its key.' d='Modules.Smstunnel.Admin'}
        <a href="{$smstunnel.dashboard_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Open the dashboard' d='Modules.Smstunnel.Admin'}</a>
      </li>
      <li>{l s='Paste the key below, add your number and save. Then send yourself a test text.' d='Modules.Smstunnel.Admin'}</li>
    </ol>
  {else}
    <h4>{l s='Your phones' d='Modules.Smstunnel.Admin'}</h4>
    {if $smstunnel.phones_error}
      <div class="alert alert-danger">{$smstunnel.phones_error|escape:'html':'UTF-8'}</div>
    {elseif !$smstunnel.phones}
      <div class="alert alert-warning">
        {l s='No phone is linked to your account yet. Install the SMSTunnel app on your Android phone and sign in, or link it from Phones, then Add phone, in your dashboard.' d='Modules.Smstunnel.Admin'}
        <a href="{$smstunnel.google_play_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Get it on Google Play' d='Modules.Smstunnel.Admin'}</a>
      </div>
    {else}
      <table class="table smstunnel-phones">
        <thead>
          <tr>
            <th>{l s='Phone' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='State' d='Modules.Smstunnel.Admin'}</th>
            <th>{l s='Phone ID' d='Modules.Smstunnel.Admin'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $smstunnel.phones as $phone}
            <tr>
              <td>
                {$phone.name|escape:'html':'UTF-8'}
                {if $smstunnel.device_id && $smstunnel.device_id == $phone.id}
                  <span class="label label-info">{l s='Sends your texts' d='Modules.Smstunnel.Admin'}</span>
                {/if}
              </td>
              <td>
                {if $phone.isOnline}
                  <span class="label label-success">{l s='Online' d='Modules.Smstunnel.Admin'}</span>
                {else}
                  <span class="label label-default">{l s='Offline' d='Modules.Smstunnel.Admin'}</span>
                {/if}
              </td>
              <td><code>{$phone.id|escape:'html':'UTF-8'}</code></td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="help-block">{l s='When the phone is offline, texts wait on your account and go out as soon as it is back online.' d='Modules.Smstunnel.Admin'}</p>
    {/if}
    {if $smstunnel.device_unknown}
      <div class="alert alert-warning">{l s='The phone ID saved below is not one of these phones. Copy it again, or leave the field empty to send from any of your phones.' d='Modules.Smstunnel.Admin'}</div>
    {/if}

    <form method="post" action="{$smstunnel.action|escape:'html':'UTF-8'}" class="form-inline smstunnel-test">
      <div class="form-group">
        <label for="SMSTUNNEL_TEST_NUMBER">{l s='Send a test text to' d='Modules.Smstunnel.Admin'}</label>
        <input type="text" class="form-control" id="SMSTUNNEL_TEST_NUMBER" name="SMSTUNNEL_TEST_NUMBER" value="{$smstunnel.test_number|escape:'html':'UTF-8'}" placeholder="+33612345678">
      </div>
      <button type="submit" class="btn btn-default" name="submitSmstunnelTest" value="1">
        <i class="icon-envelope"></i> {l s='Send a test text' d='Modules.Smstunnel.Admin'}
      </button>
    </form>
  {/if}

  {if $smstunnel.last_failure}
    <div class="alert alert-danger smstunnel-last-failure">
      <strong>{l s='Last text that did not go out:' d='Modules.Smstunnel.Admin'}</strong>
      {$smstunnel.last_failure.date|escape:'html':'UTF-8'}
      <br>{$smstunnel.last_failure.error|escape:'html':'UTF-8'}
    </div>
  {/if}
</div>
