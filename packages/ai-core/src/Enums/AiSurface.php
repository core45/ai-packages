<?php

declare(strict_types=1);

namespace Core45\AiCore\Enums;

/**
 * Identifies each AI surface for `AiClientFactory` resolution.
 *
 * Derived from the B0 mechanical call-site inventory (docs/AI_CALLSITE_INVENTORY.md).
 *
 * Detection and Humanization route to phasea's async poll surfaces like every
 * other surface. No local AI client carve-outs remain after Phase 27.
 */
enum AiSurface: string
{
    case Chat = 'chat';
    case CustomerChat = 'customer_chat';
    case Content = 'content';
    case Seo = 'seo';
    case Rewrite = 'rewrite';
    case Translate = 'translate';
    case Embeddings = 'embeddings';
    case Research = 'research';
    case Images = 'images';
    case Analytics = 'analytics';
    case BlogPipeline = 'blog_pipeline';
    case BlogTopics = 'blog_topics';
    case Knowledge = 'knowledge';
    case Feedback = 'feedback';
    case Detection = 'detection';
    case Humanization = 'humanization';
}
