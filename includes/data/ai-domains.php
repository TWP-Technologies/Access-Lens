<?php
/**
 * AI/LLM Bot Domains List
 *
 * Verified rDNS/fDNS domains for Generative AI and LLM training bots.
 *
 * @package AccessLens
 */

return [
    '.openai.com',
    '.commoncrawl.org',
    '.anthropic.com',
    '.claude.ai',
    '.perplexity.ai',
    '.you.com',
    '.diffbot.com',
    '.bytedance.com',
    '.cohere.ai',
    '.omgili.com',
    '.google.com', // Google-Extended AI crawler verification
    '.amazon.com', // Broad, but includes Amazonbot (also listed in paranoid mode intentionally)
    '.apple.com', // Applebot-Extended often comes from here or applebot.apple.com
];
