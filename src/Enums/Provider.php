<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case DeepSeek = 'deepseek';
    case ElevenLabs = 'elevenlabs';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case Mistral = 'mistral';
    case Ollama = 'ollama';
    case OpenAI = 'openai';
    case OpenRouter = 'openrouter';
    case SAIA = 'saia';
    case VoyageAI = 'voyageai';
    case XAI = 'xai';
}
