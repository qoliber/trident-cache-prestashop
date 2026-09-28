{* Trident Cache back office: the shared screens' data, escaped. *}
<div class="panel">
  <div class="panel-heading">Trident Cache</div>
  <p>
    {foreach from=$trident_screens item=s}
      <a class="btn btn-default btn-sm" href="{$trident_link|escape:'html':'UTF-8'}&amp;screen={$s|escape:'url'}">{$s|escape:'html':'UTF-8'}</a>
    {/foreach}
  </p>
  {if isset($trident.error)}<div class="alert alert-danger">{$trident.error|escape:'html':'UTF-8'}</div>{/if}
  {if isset($trident.screen) && $trident.screen == 'purge'}
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <select name="trident_action" class="form-control">
        <option value="purge_urls">URL</option><option value="purge_tags">Tag</option>
        <option value="purge_entities">Entity ids</option><option value="purge_pattern">Pattern</option>
        <option value="purge_host">Host</option><option value="purge_all">Everything</option>
      </select>
      <input type="text" name="urls[]" class="form-control" placeholder="URL">
      <input type="text" name="tags[]" class="form-control" placeholder="tag">
      <select name="kind" class="form-control"><option value="product">product</option><option value="category">category</option><option value="cms">cms</option><option value="manufacturer">brand</option></select>
      <input type="text" name="ids[]" class="form-control" placeholder="id">
      <input type="text" name="pattern" class="form-control" placeholder="pattern">
      <input type="text" name="host" class="form-control" placeholder="host">
      <label><input type="checkbox" name="confirm" value="1"> I confirm (required for pattern, host, everything)</label>
      <button class="btn btn-primary">Purge</button>
    </form>
  {/if}
  {if isset($trident.screen) && $trident.screen == 'warmer'}
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_action" value="warmer_run">
      <label>Sitemap URL(s) on this shop, one per line (.xml or .xml.gz). Empty: the warmer's configured sources
        <textarea name="sitemaps" class="form-control" rows="3" placeholder="/1_index_sitemap.xml"></textarea></label>
      <button class="btn btn-primary">Run the warmer now</button>
    </form>
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_action" value="warmer_queue_shop">
      <button class="btn btn-default">Warm this shop's pages</button>
    </form>
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_action" value="warmer_queue">
      <label>URLs or paths on this shop, one per line <textarea name="urls" class="form-control" rows="3"></textarea></label>
      <button class="btn btn-default">Warm these URLs</button>
    </form>
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_action" value="warmer_cancel">
      <button class="btn btn-default">Cancel the running warm-up</button>
    </form>
  {/if}
  {if isset($trident.screen) && $trident.screen == 'settings'}
    <form method="post" action="{$trident_link|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_token" value="{$trident.token|escape:'html':'UTF-8'}">
      <input type="hidden" name="trident_settings" value="1">
      <label>API URL <input type="text" name="api_url" class="form-control" value="{$trident.data.api_url|escape:'html':'UTF-8'}"></label>
      <label>API token <input type="password" name="api_token" class="form-control" autocomplete="off" placeholder="{if $trident.data.has_token}(stored — leave empty to keep){/if}"></label>
      <label>Purge mode <select name="purge_mode" class="form-control"><option value="soft">soft</option><option value="hard" {if $trident.data.mode == 'hard'}selected{/if}>hard</option></select></label>
      <button class="btn btn-primary">Save</button>
    </form>
  {/if}
  <pre style="max-height:70vh;overflow:auto">{$trident_json|escape:'html':'UTF-8'}</pre>
</div>
