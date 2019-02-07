<?php

namespace App\Libraries\Markdown;

use League\CommonMark\ElementRendererInterface;
use League\CommonMark\Inline\Element\AbstractInline;
use League\CommonMark\Inline\Element\AbstractStringContainer;
use League\CommonMark\Inline\Renderer\InlineRendererInterface;


class InlineTextRenderer implements InlineRendererInterface
{
    /**
     * @param AbstractInline $inline
     * @param ElementRendererInterface $htmlRenderer
     *
     * @return string
     */
    public function render(AbstractInline $inline, ElementRendererInterface $renderer)
    {
        if ($inline instanceof AbstractStringContainer) {
            return $inline->getContent();
        } else {
            return $renderer->renderInlines($inline->children());
        }
    }
}
