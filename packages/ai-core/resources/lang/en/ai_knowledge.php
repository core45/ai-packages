<?php

declare(strict_types=1);

return [
    'navigation' => 'AI Knowledge',
    'model_label' => 'AI Knowledge Entry',
    'model_plural_label' => 'AI Knowledge Entries',

    'gaps_navigation' => 'Knowledge Gaps',
    'gaps_model_label' => 'Knowledge Gap',
    'gaps_model_plural_label' => 'Knowledge Gaps',

    'conflicts_navigation' => 'Knowledge Conflicts',
    'conflicts_model_label' => 'Knowledge Conflict',
    'conflicts_model_plural_label' => 'Knowledge Conflicts',

    'section_content' => 'Content',
    'section_content_description' => 'The knowledge entry title, body, and keywords.',
    'section_classification' => 'Classification',

    'field' => [
        'title' => 'Title',
        'content' => 'Content',
        'keywords' => 'Keywords',
        'type' => 'Type',
        'tier' => 'Tier',
        'audience' => 'Audience',
        'enabled' => 'Enabled',
        'use_count' => 'Use Count',
        'last_used_at' => 'Last Used At',
        'created_by' => 'Created By',
    ],

    'placeholder' => [
        'title' => 'Enter a short, descriptive title',
        'content' => 'Enter the knowledge content',
        'keywords' => 'Enter comma-separated keywords',
    ],

    'help' => [
        'keywords' => 'Keywords help match this entry against relevant queries.',
        'tier' => 'Critical knowledge is always included; contextual knowledge is included when relevant.',
        'audience' => 'Controls which audience this knowledge entry is shown to.',
        'enabled' => 'Disabled entries are excluded from AI context.',
    ],

    'type' => [
        'instruction' => 'Instruction',
        'fact' => 'Fact',
        'faq' => 'FAQ',
        'forbidden' => 'Forbidden',
        'required' => 'Required',
    ],

    'tier' => [
        'critical' => 'Critical',
        'contextual' => 'Contextual',
    ],

    'audience' => [
        'public' => 'Public',
        'customer' => 'Customer',
        'admin' => 'Admin',
    ],

    'gap_field' => [
        'status' => 'Status',
        'occurrence_count' => 'Occurrence Count',
        'question' => 'Question',
        'confidence_score' => 'Confidence Score',
        'last_occurred_at' => 'Last Occurred At',
        'ai_response' => 'AI Response',
        'resolved_by' => 'Resolved By',
        'resolved_at' => 'Resolved At',
        'resolution_knowledge' => 'Resolution Knowledge',
    ],

    'gap_status' => [
        'open' => 'Open',
        'resolved' => 'Resolved',
        'ignored' => 'Ignored',
    ],

    'gap_action' => [
        'resolve' => 'Resolve',
        'ignore' => 'Ignore',
    ],

    'conflict_field' => [
        'status' => 'Status',
        'type' => 'Type',
        'knowledge_a' => 'Knowledge A',
        'knowledge_b' => 'Knowledge B',
        'overlap_keywords' => 'Overlap Keywords',
        'overlap_score' => 'Overlap Score',
        'resolved_by' => 'Resolved By',
        'resolved_at' => 'Resolved At',
        'resolution_notes' => 'Resolution Notes',
    ],

    'conflict_placeholder' => [
        'resolution_notes' => 'Describe how this conflict was resolved',
    ],

    'conflict_status' => [
        'detected' => 'Detected',
        'reviewed' => 'Reviewed',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ],

    'conflict_type' => [
        'direct_contradiction' => 'Direct Contradiction',
        'partial_overlap' => 'Partial Overlap',
        'ambiguous' => 'Ambiguous',
    ],

    'conflict_action' => [
        'resolve' => 'Resolve',
        'dismiss' => 'Dismiss',
        'dismiss_heading' => 'Dismiss Conflict',
        'dismiss_description' => 'Are you sure you want to dismiss this conflict without resolving it?',
        'dismiss_selected' => 'Dismiss Selected',
    ],

    'conflict_notification' => [
        'resolved' => 'Conflict resolved',
        'dismissed' => 'Conflict dismissed',
        'bulk_dismissed' => 'Conflicts dismissed',
    ],

    'conflict_stat' => [
        'unresolved' => 'Unresolved',
        'unresolved_description' => 'Conflicts awaiting review',
        'contradictions' => 'Contradictions',
        'contradictions_description' => 'Direct contradictions detected',
        'resolved' => 'Resolved',
        'resolved_description' => 'Conflicts resolved',
        'total' => 'Total',
        'total_description' => 'All detected conflicts',
    ],
];
