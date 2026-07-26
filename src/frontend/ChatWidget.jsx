import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import ReactMarkdown from 'react-markdown';

const linkifyText = (text) => {
    if (!text) return '';

    // Split by markdown link or HTML tag to avoid replacing URLs inside them
    const parts = text.split(/(\[[^\]]+\]\([^)]+\)|<[^>]+>)/g);
    return parts.map(part => {
        // If this part is a markdown link or an HTML tag, return it unmodified
        if (/^\[.+\]\(.+\)$/.test(part) || /^<.+>$/.test(part)) {
            return part;
        }
        // Otherwise, find raw URLs and convert them to markdown links
        const urlRegex = /(https?:\/\/[^\s\)<>"]+)/gi;
        return part.replace(urlRegex, (match) => {
            let url = match;
            let suffix = '';
            const punctuation = /[.,;:!]$/;
            if (punctuation.test(url)) {
                suffix = url.slice(-1);
                url = url.slice(0, -1);
            }
            return `[${url}](${url})${suffix}`;
        });
    }).join('');
};

export default function ChatWidget({ settings, inline }) {
    const bot = settings?.chatbot || {};
    const disp = settings?.display || {};

    const getErrorMessage = () => {
        const defaultTemplate = "There is some error on server, please contact our [support agent]({support_url}).";
        const template = bot.api_error_msg || defaultTemplate;
        const supportUrl = bot.support_url || '#';
        return template.replace('{support_url}', supportUrl);
    };

    const suggestedQuestions = bot.enable_pre_questions ? [
        bot.pre_question_1,
        bot.pre_question_2,
        bot.pre_question_3,
        bot.pre_question_4,
    ].filter(q => q && q.trim()) : [];

    const [isOpen, setIsOpen] = useState(false);
    const [input, setInput] = useState('');
    const [isTyping, setIsTyping] = useState(false);

    const [sessionId, setSessionId] = useState(() => {
        return window.baca_frontend_data?.session_id || ('sess_' + Math.random().toString(36).substr(2, 9));
    });

    const [clearAllowed, setClearAllowed] = useState(() => window.baca_frontend_data?.clear_allowed ?? true);

    const [messages, setMessages] = useState([]);

    // Email capture state variables
    const [userEmail, setUserEmail] = useState('');
    const [emailInput, setEmailInput] = useState('');
    const [emailError, setEmailError] = useState('');

    const [pendingMessage, setPendingMessage] = useState('');

    const messagesEndRef = useRef(null);
    const textareaRef = useRef(null);
    const widgetRef = useRef(null);
    const isMountedRef = useRef(true);

    useEffect(() => {
        return () => {
            isMountedRef.current = false;
        };
    }, []);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (widgetRef.current && !widgetRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };

        if (isOpen && !inline) {
            document.addEventListener('mousedown', handleClickOutside);
            document.addEventListener('touchstart', handleClickOutside);
        }

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('touchstart', handleClickOutside);
        };
    }, [isOpen, inline]);

    useEffect(() => {
        // Delay opening based on trigger logic
        if (disp.trigger_type === 'delay' && disp.entire_site && !inline) {
            const delayMs = (parseInt(disp.trigger_delay, 10) || 3) * 1000;
            const timer = setTimeout(() => {
                setIsOpen(true);
            }, delayMs);
            return () => clearTimeout(timer);
        }
    }, [disp.trigger_type, disp.entire_site, disp.trigger_delay, inline]);

    useEffect(() => {
        if (messagesEndRef.current) {
            messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [messages, isTyping]);

    const toggleChat = () => setIsOpen(prev => !prev);

    const handleEmailSubmit = async (e) => {
        if (e) e.preventDefault();
        const trimmed = emailInput.trim();
        // Basic email regex
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!trimmed) {
            setEmailError(__('Please enter your email.', 'botisst-ai-chat-assistant'));
            return;
        }
        if (!emailRegex.test(trimmed)) {
            setEmailError(__('Please enter a valid email address.', 'botisst-ai-chat-assistant'));
            return;
        }
        setUserEmail(trimmed);

        const msgToSend = pendingMessage || (messages.length === 1 ? messages[0].content : '');

        if (msgToSend) {
            setIsTyping(true);
            setPendingMessage('');
            try {
                const response = await apiFetch({
                    path: '/baca/v1/chat',
                    method: 'POST',
                    data: { prompt: msgToSend, session_id: sessionId, email: trimmed }
                });

                if (!isMountedRef.current) return;

                if (response.success) {
                    if (response.session_id && response.session_id !== sessionId) {
                        setSessionId(response.session_id);
                    }
                    if (response.messages && response.messages.length > 0) {
                        setMessages(response.messages);
                    } else {
                        let msgContent = response.message;
                        if (typeof msgContent === 'object' && msgContent !== null) {
                            msgContent = msgContent.text || msgContent.content || JSON.stringify(msgContent);
                        }
                        setMessages([{ role: 'user', content: msgToSend }, { role: 'bot', content: msgContent }]);
                    }
                } else {
                    setMessages(prev => [...prev, { role: 'error', content: getErrorMessage() }]);
                }
            } catch (error) {
                if (isMountedRef.current) {
                    setMessages(prev => [...prev, { role: 'error', content: getErrorMessage() }]);
                }
            } finally {
                if (isMountedRef.current) {
                    setIsTyping(false);
                }
            }
        }
    };

    const handleClearChat = async () => {
        try {
            // Call PHP REST API to clear session and re-initialize cookies
            const response = await apiFetch({
                path: '/baca/v1/clear-session',
                method: 'POST'
            });

            if (response.success && response.session_id) {
                // Reset React states
                setMessages([]);
                setSessionId(response.session_id);
                setUserEmail('');
                setEmailInput('');
                setPendingMessage('');
                setClearAllowed(true);
            }
        } catch (error) {
            // Local fallback in case of connection failure
            setMessages([]);
            setUserEmail('');
            setEmailInput('');
            setPendingMessage('');
            const newSid = 'sess_' + Math.random().toString(36).substr(2, 9);
            setSessionId(newSid);
            setClearAllowed(true);
        }
    };

    const saveChatEnabled = !!bot.save_chat;
    const askEmailEnabled = saveChatEnabled && !!bot.ask_email;

    const handleSend = async (e, directText = null) => {
        if (e) e.preventDefault();
        if (isTyping) return;
        const text = (directText !== null ? directText : input).trim();
        if (!text) return;

        // If we don't have an email yet and database saving + email asking are enabled, save the user message, set pending, and wait for email input
        if (askEmailEnabled && !userEmail) {
            setMessages([{ role: 'user', content: text }]);
            setPendingMessage(text);
            setInput('');
            return;
        }

        // Normal message submit
        setMessages(prev => [...prev, { role: 'user', content: text }]);
        if (directText === null) {
            setInput('');
        }
        setIsTyping(true);
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
        }

        try {
            const response = await apiFetch({
                path: '/baca/v1/chat',
                method: 'POST',
                data: { prompt: text, session_id: sessionId, email: userEmail }
            });

            if (!isMountedRef.current) return;

            if (response.success) {
                if (response.session_id && response.session_id !== sessionId) {
                    setSessionId(response.session_id);
                }
                if (response.messages && response.messages.length > 0) {
                    setMessages(response.messages);
                } else {
                    let msgContent = response.message;
                    if (typeof msgContent === 'object' && msgContent !== null) {
                        msgContent = msgContent.text || msgContent.content || JSON.stringify(msgContent);
                    }
                    setMessages(prev => [...prev, { role: 'bot', content: msgContent }]);
                }
            } else {
                setMessages(prev => [...prev, { role: 'error', content: getErrorMessage() }]);
            }
        } catch (error) {
            if (isMountedRef.current) {
                setMessages(prev => [...prev, { role: 'error', content: getErrorMessage() }]);
            }
        } finally {
            if (isMountedRef.current) {
                setIsTyping(false);
            }
        }
    };

    const primaryColor = bot.primary_color || '#2563eb';
    const avatarSrc = bot.bot_avatar || '';
    const bubbleClass = `baca-bubble-${bot.bubble_style || 'rounded'}`;
    const isWithin30Mins = clearAllowed;
    const showEmailForm = askEmailEnabled && !userEmail && messages.length > 0;

    return (
        <div ref={widgetRef} className={`baca-chat-wrapper ${inline ? 'baca-chat-inline' : `baca-chat-floating ${disp.position}`}`} style={{ '--baca-primary': primaryColor }}>
            {(isOpen || inline) && (
                <div id="baca-chat-window" className={`baca-chat-window ${bubbleClass}`} style={{ display: 'flex' }}>
                    <div className="baca-chat-header">
                        <div className="baca-chat-avatar">
                            {avatarSrc ? (
                                <img src={avatarSrc} alt={__('Avatar', 'botisst-ai-chat-assistant')} />
                            ) : (
                                <span>{(bot.bot_name || 'V').charAt(0).toUpperCase()}</span>
                            )}
                        </div>
                        <div className="baca-chat-info">
                            <strong>{bot.bot_name || 'Virtual Assistant'}</strong>
                            <span>{__('Online', 'botisst-ai-chat-assistant')}</span>
                        </div>
                        {isWithin30Mins && (
                            <button className="baca-chat-clear" onClick={handleClearChat} title={__('Clear Chat', 'botisst-ai-chat-assistant')}>
                                <span className="dashicons dashicons-trash" aria-hidden="true"></span>
                            </button>
                        )}
                        {!inline && (
                            <button className="baca-chat-close" onClick={toggleChat} title={__('Close', 'botisst-ai-chat-assistant')}>
                                <span className="dashicons dashicons-no-alt" aria-hidden="true" />
                            </button>
                        )}
                    </div>

                    <div className="baca-chat-messages" id="baca-messages">
                        {bot.greeting_msg && messages.length === 0 && suggestedQuestions.length === 0 && (
                            <div className="baca-message baca-message-bot">
                                <p>{bot.greeting_msg}</p>
                            </div>
                        )}
                        {messages.length === 0 && suggestedQuestions.length > 0 && (
                            <div className="baca-suggested-questions">
                                {suggestedQuestions.map((q, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        className={`baca-suggested-question-btn baca-suggested-question-btn--${bot.pre_questions_border_radius || 'rounded'}`}
                                        style={{
                                            backgroundColor: bot.pre_questions_bg_color || '#ffffff',
                                            color: bot.pre_questions_text_color || '#475569',
                                            borderColor: bot.pre_questions_border_color || '#e2e8f0',
                                        }}
                                        onClick={(e) => handleSend(e, q)}
                                    >
                                        {q}
                                    </button>
                                ))}
                            </div>
                        )}
                        {messages.map((msg, idx) => (
                            <div key={idx} className={`baca-message ${msg.role === 'bot' ? 'baca-message-bot' : (msg.role === 'error' ? 'baca-message-error' : 'baca-message-user')}`}>
                                {msg.role === 'bot' || msg.role === 'error' ? (
                                    <div className="baca-message-content">
                                        <ReactMarkdown
                                            components={{
                                                a: ({ node, ...props }) => (
                                                    <a {...props} target="_blank" rel="noopener noreferrer" />
                                                ),
                                                p: ({ node, ...props }) => <p {...props} />,
                                                h3: ({ node, ...props }) => <h3 {...props} />,
                                                strong: ({ node, ...props }) => <strong {...props} />,
                                                em: ({ node, ...props }) => <em {...props} />,
                                                ul: ({ node, ...props }) => <ul {...props} />,
                                                li: ({ node, ...props }) => <li {...props} />,
                                                hr: ({ node, ...props }) => <hr {...props} />,
                                            }}
                                        >
                                            {linkifyText(msg.content)}
                                        </ReactMarkdown>
                                    </div>
                                ) : (
                                    <p>{msg.content}</p>
                                )}
                            </div>
                        ))}
                        {isTyping && (
                            <div className="baca-message baca-message-bot">
                                <div className="baca-typing">
                                    <span></span><span></span><span></span>
                                </div>
                            </div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>

                    {showEmailForm ? (
                        <div className="baca-email-capture-container">
                            <form className="baca-email-capture-form" onSubmit={handleEmailSubmit}>
                                <h3>{__('One last step!', 'botisst-ai-chat-assistant')}</h3>
                                <p>{__('Please enter your email to get your response and continue.', 'botisst-ai-chat-assistant')}</p>

                                <div className="baca-email-input-group">
                                    <input
                                        type="email"
                                        className="baca-email-input"
                                        placeholder={__('your.email@example.com', 'botisst-ai-chat-assistant')}
                                        value={emailInput}
                                        onChange={(e) => {
                                            setEmailInput(e.target.value);
                                            setEmailError('');
                                        }}
                                        required
                                    />
                                    {emailError && <span className="baca-email-error">{emailError}</span>}
                                </div>

                                <button type="submit" className="baca-email-submit" style={{ background: primaryColor }}>
                                    {__('Get Response', 'botisst-ai-chat-assistant')}
                                </button>
                            </form>
                        </div>
                    ) : (
                        <div className="baca-chat-input-area">
                            <textarea
                                ref={textareaRef}
                                className="baca-chat-input"
                                value={input}
                                onChange={(e) => {
                                    setInput(e.target.value);
                                    e.target.style.height = 'auto';
                                    e.target.style.height = Math.min(e.target.scrollHeight, 150) + 'px';
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && !e.shiftKey) {
                                        e.preventDefault();
                                        handleSend(e);
                                    }
                                }}
                                placeholder={__('Type a message...', 'botisst-ai-chat-assistant')}
                                rows={1}
                                disabled={isTyping}
                                style={{ resize: 'none', overflowY: 'auto', minHeight: '44px', lineHeight: '20px', padding: '12px 20px', boxSizing: 'border-box' }}
                            />
                            <button className="baca-chat-send" onClick={handleSend} disabled={isTyping} aria-label={__('Send message', 'botisst-ai-chat-assistant')}>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path
                                        d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    />
                                </svg>
                            </button>
                        </div>
                    )}
                </div>
            )}

            {!inline && !isOpen && (
                <div id="baca-launcher" className="baca-chat-launcher" onClick={toggleChat} style={{ background: primaryColor }}>
                    <span className="baca-launcher-text">{disp.launcher_text}</span>
                    <span className="dashicons dashicons-format-chat"></span>
                </div>
            )}
        </div>
    );
}
