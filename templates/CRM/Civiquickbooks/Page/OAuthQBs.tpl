{foreach from=$output key=key item=item}
    {if $key == 'message'}
        <h3 id="{$key}">{$item}</h3>
    {else}
        <p id="{$key}">{$item}</p>
    {/if}

{/foreach}
