=== Botisst – AI Chatbot & Assistant ===
Contributors: lionecoders, deep7197, mveous
Tags: chatbot, ai chatbot, wordpress chatbot, chatgpt, openai, gemini, ai assistant, customer support, live chat, rag
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot for WordPress powered by OpenAI and Google Gemini. Train it with your website knowledge base using built-in RAG to answer visitor questions.

== Description ==

Botisst is an AI chatbot plugin for WordPress that lets you build an intelligent ChatGPT and Gemini powered chatbot for your website. Train the chatbot using your own content, PDFs, website pages, posts, and documents with built-in Retrieval-Augmented Generation (RAG). Botisst helps automate customer support, answer visitor questions, generate leads, and provide instant AI assistance without relying on third-party chatbot platforms.

Botisst includes an advanced Retrieval-Augmented Generation (RAG) engine built right in. You can train the AI on your own content by adding text snippets, website URLs, and existing WordPress pages or posts. Your knowledge base is securely indexed and stored using either a built-in local SQLite vector database (zero configuration required) or Pinecone (for scalable cloud storage).

= Key Features =
* **Multiple LLM Providers**: Connect directly and securely to OpenAI or Google Gemini. Easily configure fallbacks or switch models in seconds.
* **Train Your AI Chatbot (RAG)**: Feed the chatbot custom text snippets, documents, or website links. The built-in Retrieval-Augmented Generation (RAG) engine scans and indexes knowledge to provide accurate answers.
* **Advanced Vector Databases**: Out-of-the-box support for a local SQLite vector store, or integrate with cloud services like Pinecone.
* **Modern AI Chat Widget**: Snappy, beautiful admin dashboard (with a live chat preview) and a performant, non-blocking floating front-end widget.
* **Elementor Page Builder Support**: Easily embed and customize your AI Assistant using the custom Elementor widget.
* **Flexible Shortcode Integration**: Use the `[botisst_ai]` shortcode to easily render the chat interface inline on any page or post.
* **Chat Logs & Sessions**: View and review interactive visitor chat transcripts directly from the WordPress admin panel.
* **Context & Memory Optimization**: Smart backend token optimization that automatically keeps context history concise, saving you API costs.

= Perfect For =

* AI chatbot for WordPress
* Customer support chatbot
* ChatGPT chatbot
* Google Gemini chatbot
* Lead generation chatbot
* AI website assistant
* Knowledge base chatbot
* FAQ chatbot
* WooCommerce chatbot

Train your WordPress AI chatbot using:

* WordPress Posts
* WordPress Pages
* PDFs
* Text snippets
* Website URLs
* FAQs
* WooCommerce products

### How to Get Your API Keys
To get started, you'll need an API key from one of the supported providers:
**OpenAI (ChatGPT):** [Get your OpenAI API key here](https://platform.openai.com/api-keys)
**Google Gemini:** [Get your Gemini API key here](https://aistudio.google.com/app/apikey)

== Installation ==

Setting up Botisst takes less than 5 minutes:
1. **Upload & Install**: Search for "Botisst" in your WordPress admin under *Plugins > Add New*, or upload the plugin folder directly to `/wp-content/plugins/`.
2. **Activate**: Click **Activate** on your Plugins screen.
3. **Run Setup Wizard**: Upon activation, you will automatically be redirected to the quick Setup Wizard.
4. **Configure Requirements**: Follow the guided steps to link your OpenAI or Google Gemini API key and choose your database.
5. **Launch!**: Once setup is complete, customize how the chatbot looks, turn on **Global Visibility**, and you're good to go!

= Why Choose Botisst? =
Unlike many AI chatbot plugins, Botisst connects directly to your own OpenAI or Google Gemini account. There are no monthly platform fees, no external chatbot service, and no vendor lock-in. Your AI chatbot runs entirely on your own API keys while giving you full control over costs, privacy, and customization.

== Frequently Asked Questions ==

= Do I need a paid subscription to use this? =
No monthly fees! You only pay for what you use directly through your OpenAI or Google Gemini developer accounts. Both platforms offer generous free tiers or low pay-as-you-go pricing.

= Can I put the chat inside a page instead of a floating bubble? =
Absolutely. Just paste the `[botisst_ai]` shortcode anywhere on a page or post, and the chat interface will load inline right where you put it.

= Where is my data sent? =
Your chat messages are sent directly to the AI provider you configure (OpenAI or Google Gemini) to generate responses. We don't route your requests through any third-party servers, keeping your data private and secure.

### A Friendly Disclaimer
Because our chatbot uses language models (like OpenAI and Gemini), it might occasionally write things that aren't 100% accurate. We recommend keeping an eye on your prompt instructions and double-checking important info.

### Privacy & Terms
If you'd like to learn more about how OpenAI and Google handle data, check out their official terms:
* [OpenAI Privacy Policy](https://openai.com/policies/row-privacy-policy/)
* [OpenAI Terms of Use](https://openai.com/policies/row-terms-of-use/)
* [Google Gemini Privacy Policy](https://safety.google/intl/en_in/products/gemini/)
* [Google Gemini API Terms](https://ai.google.dev/gemini-api/terms)

== Screenshots ==

1. Botisst Setup Wizard.
2. Botisst Settings Dashboard.
3. Botisst Chat Widget on front-end.

== Changelog ==

= 1.0.0 =
* Initial stable release.
* React-based UI overhaul.