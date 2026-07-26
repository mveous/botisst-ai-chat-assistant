# Botisst — AI Chat Assistant

[![WordPress Support](https://img.shields.io/badge/WordPress-%3E%3D%205.8-blue.svg)](https://wordpress.org)
[![PHP Support](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF.svg)](https://secure.php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A friendly, custom AI assistant for your WordPress site. Connect OpenAI or Google Gemini in seconds, train it with your own knowledge base, and chat with your visitors in real-time.

---

## Description

Meet **Botisst**, your new AI-powered website assistant. Whether you want to answer customer support questions, guide visitors to the right pages, or just give your users a fun, interactive way to learn about your site, Botisst makes it easy.

Unlike other clunky chatbot plugins, Botisst is built with a snappy React interface. It connects directly and securely to top-tier AI models (OpenAI and Google Gemini), meaning you don't have to pay for expensive middleman subscriptions. You get complete control over how your chatbot behaves, how it looks, and what knowledge it uses to answer questions.

Give your visitors a smarter experience without slowing down your site.

---

## Key Features

* **Multiple LLM Providers**: Connect directly and securely to OpenAI or Google Gemini. Easily configure fallbacks or switch models in seconds.
* **Train Your Assistant (RAG)**: Feed the chatbot custom text snippets, documents, or website links. The built-in Retrieval-Augmented Generation (RAG) engine scans and indexes knowledge to provide accurate answers.
* **Advanced Vector Databases**: Out-of-the-box support for a local SQLite vector store, or integrate with cloud services like Pinecone.
* **Modern React Settings & Widget**: Snappy, beautiful React admin dashboard (with a live chat preview) and a performant, non-blocking floating front-end widget.
* **Elementor Page Builder Support**: Easily embed and customize your AI Assistant using the custom Elementor widget.
* **Flexible Shortcode Integration**: Use the `[botisst_ai]` shortcode to easily render the chat interface inline on any page or post.
* **Chat Logs & Sessions**: View and review interactive visitor chat transcripts directly from the WordPress admin panel.
* **Context & Memory Optimization**: Smart backend token optimization that automatically keeps context history concise, saving you API costs.

---

## Getting Started

### How to Get Your API Keys
To get started, you'll need an API key from one of the supported providers:
* **OpenAI (ChatGPT):** [Get your OpenAI API key here](https://platform.openai.com/api-keys)
* **Google Gemini:** [Get your Gemini API key here](https://aistudio.google.com/app/apikey)

### Installation
Setting up Botisst takes less than 5 minutes:
1. **Upload & Install**: Search for "Botisst" in your WordPress admin under *Plugins > Add New*, or upload the plugin folder directly to `/wp-content/plugins/`.
2. **Activate**: Click **Activate** on your Plugins screen.
3. **Open Settings**: Click on the new **Botisst** menu item in your WordPress sidebar.
4. **Link Your AI**: Go to the **API Credentials** tab and paste your OpenAI or Google Gemini key.
5. **Launch!**: Customize how the chatbot looks, turn on **Global Visibility**, and you're good to go!

---

## Frequently Asked Questions

#### Do I need a paid subscription to use this?
No monthly fees! You only pay for what you use directly through your OpenAI or Google Gemini developer accounts. Both platforms offer generous free tiers or low pay-as-you-go pricing.

#### Can I put the chat inside a page instead of a floating bubble?
Absolutely. Just paste the `[botisst_ai]` shortcode anywhere on a page or post, and the chat interface will load inline right where you put it.

#### Where is my data sent?
Your chat messages are sent directly to the AI provider you configure (OpenAI or Google Gemini) to generate responses. We don't route your requests through any third-party servers, keeping your data private and secure.

---

## Privacy & Terms
If you'd like to learn more about how OpenAI and Google handle data, check out their official terms:
* [OpenAI Privacy Policy](https://openai.com/policies/row-privacy-policy/)
* [OpenAI Terms of Use](https://openai.com/policies/row-terms-of-use/)
* [Google Gemini Privacy Policy](https://safety.google/intl/en_in/products/gemini/)
* [Google Gemini API Terms](https://ai.google.dev/gemini-api/terms)

> [!NOTE]
> Because our chatbot uses language models (like OpenAI and Gemini), it might occasionally write things that aren't 100% accurate. We recommend keeping an eye on your prompt instructions and double-checking important info.

---

## Changelog

### 1.0.1
* Improve Folder Structure.
* Minor Textual Changes.

### 1.0.0
* Initial stable release.
* React-based UI overhaul.