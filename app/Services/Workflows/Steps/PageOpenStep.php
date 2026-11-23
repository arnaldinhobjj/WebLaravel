<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\Expressions;
use App\Services\Workflows\Links;
use App\Services\Workflows\RunContext;

/**
 * `page/open`: sends the person to a Fort Vale page or a website at the end
 * of a Run-button run.
 */
class PageOpenStep extends BaseStep
{
    protected function keys(): array
    {
        return ['url' => true];
    }

    protected function checks(array $with, string $path): array
    {
        $url = $with['url'] ?? null;

        if (! is_string($url) || Expressions::hasPlaceholder($url) || Links::isPageOrWebAddress($url)) {
            return is_string($url) || $url === null ? [] : ["{$path}.url: must be text."];
        }

        return ["{$path}.url: must be a path starting with / (like /ticket) or a web address starting with https://."];
    }

    public function run(array $with, RunContext $context): array
    {
        $url = (string) $with['url'];

        if (! Links::isPageOrWebAddress($url)) {
            throw new StepFailed("\"{$url}\" isn't a Fort Vale path or an http(s) web address.");
        }

        $context->redirect = $url;

        return ['url' => $url];
    }

    public function redirects(): bool
    {
        return true;
    }
}
