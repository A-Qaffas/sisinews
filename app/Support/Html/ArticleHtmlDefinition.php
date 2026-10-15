<?php

namespace App\Support\Html;

use HTMLPurifier_HTMLDefinition;
use Stevebauman\Purify\Definitions\Definition;

/**
 * HTMLPurifier knows HTML 4 only, so the two HTML5 elements stories use are
 * declared here, and external links get their rel hardening.
 */
final class ArticleHtmlDefinition implements Definition
{
    public static function apply(HTMLPurifier_HTMLDefinition $definition): void
    {
        $definition->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
        $definition->addElement('figcaption', 'Inline', 'Flow', 'Common');

        // Registered last so it runs after the stock target=noopener/noreferrer transforms.
        $definition->addBlankElement('a')->attr_transform_post[] = new ExternalLinkRel;
    }
}
