<?php

namespace App\Support\Html;

use HTMLPurifier_AttrTransform;

/**
 * Every link that leaves the site (http/https to another host) is forced to
 * rel="noopener noreferrer nofollow", whatever the editor typed. Same-site,
 * relative, mailto: and tel: links are left alone.
 */
final class ExternalLinkRel extends HTMLPurifier_AttrTransform
{
    public function transform($attr, $config, $context)
    {
        if (! isset($attr['href'])) {
            return $attr;
        }

        $url = (new \HTMLPurifier_URIParser)->parse($attr['href']);
        $scheme = $url === false ? false : $url->getSchemeObj($config, $context);

        if ($scheme && $scheme->browsable && ! $url->isLocal($config, $context)) {
            $attr['rel'] = 'noopener noreferrer nofollow';
        }

        return $attr;
    }
}
