(function($) {
    // --- フロントエンドのFAQ表示 (アコーディオン) ---
    function initializeFaqAccordion() {
        var container = $('#gemini-faq-container');
        if (container.length === 0 || container.data('initialized')) {
            return; // コンテナがない、または初期化済みの場合は何もしない
        }

        // AJAXでFAQを読み込む
        $.ajax({
            url: geminiFaqAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'gemini_faq',
                nonce: geminiFaqAjax.nonce,
                post_id: container.data('post-id'),
                current_page_url: window.location.href
            },
            beforeSend: function() {
                container.html('<p>FAQを生成中...</p>');
            },
            success: function(response) {
                if (response.success) {
                    var faqs = response.data;
                    if (Array.isArray(faqs) && faqs.length > 0) {
                        var html = '';
                        $.each(faqs, function(index, faq) {
                            html += '<details class="gemini-faq-item">';
                            html += '<summary class="gemini-faq-question">' + faq.question + '</summary>';
                            html += '<div class="gemini-faq-answer"><p>' + faq.answer.replace(/\n/g, '<br>') + '</p></div>';
                            html += '</details>';
                        });
                        container.html(html);
                    } else {
                        container.html('<p>このページに関連するFAQは見つかりませんでした。</p>');
                    }
                } else {
                    container.html('<p class="gemini-faq-error">FAQの読み込みに失敗しました。</p>');
                }
            },
            error: function() {
                container.html('<p class="gemini-faq-error">FAQの読み込み中にエラーが発生しました。</p>');
            }
        });

        container.data('initialized', true);
    }

    // --- 管理画面のFAQ再生成 ---
    function initializeAdminFaqGenerator() {
        if (typeof geminiFaqAdminAjax === 'undefined') {
            return; // 管理画面用のデータがなければ何もしない
        }

        $('#gemini_faq_regenerate_button').on('click', function() {
            var button = $(this);
            var spinner = $('#gemini_faq_spinner');
            var textarea = $('#gemini_faq_content_textarea');

            if (button.is(':disabled')) {
                return;
            }

            // 投稿がまだ保存されていない場合のアラート
            if ( ! geminiFaqAdminAjax.post_id || geminiFaqAdminAjax.post_id === 0 ) {
                alert('FAQを生成する前に、まず投稿を保存（下書き保存）してください。');
                return;
            }

            // 確認ダイアログ
            if ( ! confirm('現在の編集内容は破棄されます。本当にFAQを再生成しますか？') ) {
                return;
            }

            $.ajax({
                url: geminiFaqAdminAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'gemini_faq_regenerate',
                    nonce: geminiFaqAdminAjax.nonce,
                    post_id: geminiFaqAdminAjax.post_id
                },
                beforeSend: function() {
                    button.prop('disabled', true);
                    spinner.addClass('is-active');
                    textarea.val('新しいFAQを生成しています...');
                },
                success: function(response) {
                    if (response.success) {
                        textarea.val(response.data.faq_content);
                        alert('新しいFAQが生成されました。内容を確認し、投稿を更新して保存してください。');
                    } else {
                        alert('FAQの生成に失敗しました: ' + (response.data ? response.data : '不明なエラー'));
                        textarea.val('エラーが発生しました。再度お試しください。');
                    }
                },
                error: function() {
                    alert('サーバーとの通信中にエラーが発生しました。');
                    textarea.val('エラーが発生しました。再度お試しください。');
                },
                complete: function() {
                    button.prop('disabled', false);
                    spinner.removeClass('is-active');
                }
            });
        });
    }

    // DOMが読み込まれたら実行
    $(document).ready(function() {
        initializeFaqAccordion();
        initializeAdminFaqGenerator();
    });

})(jQuery);