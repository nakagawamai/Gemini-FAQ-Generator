(function($) {
    $(document).ready(function() {
        var container = $('#gemini-faq-container');
        if (container.length === 0) {
            return; // コンテナがない場合は何もしない
        }

        var postId = container.data('post-id'); // PHPから渡される投稿ID
        if (!postId) {
            // ショートコードが投稿内で使われている場合、現在の投稿IDを取得
            // ただし、これは確実ではないため、PHP側で渡すのが理想
            // 現状はダミーで0を渡すか、エラーとする
            postId = 0; // またはエラー処理
        }

        $.ajax({
            url: geminiFaqAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'gemini_faq',
                nonce: geminiFaqAjax.nonce,
                post_id: postId,
                // 現在のページのURLを渡す
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
                        container.addClass('faq-accordion'); // メインのアコーディオンクラスを追加
                        $.each(faqs, function(index, faq) {
                            html += '<div class="faq-item">' +
                                '<button class="faq-question-button">' +
                                    '<span class="faq-question-text">' + faq.question + '</span>' +
                                    '<span class="faq-toggle-icon">+</span>' +
                                '</button>' +
                                '<div class="faq-answer-content">' +
                                    '<p>' + faq.answer + '</p>' +
                                '</div>' +
                            '</div>';
                        });
                        container.html(html);

                        // コンテンツがロードされた後にアコーディオン機能を追加
                        container.find('.faq-question-button').on('click', function() {
                            const faqItem = $(this).closest('.faq-item');
                            const answerContent = faqItem.find('.faq-answer-content');
                            const toggleIcon = $(this).find('.faq-toggle-icon');

                            faqItem.toggleClass('active');

                            if (faqItem.hasClass('active')) {
                                // 開く場合: max-heightをautoにしてからscrollHeightを取得し、アニメーション
                                answerContent.css('max-height', 'none'); // 一時的にmax-heightを解除
                                const scrollHeight = answerContent[0].scrollHeight;
                                answerContent.css('max-height', '0'); // アニメーション開始のため0に設定
                                answerContent[0].offsetHeight; // 強制リフロー
                                answerContent.css('max-height', scrollHeight + 'px');
                                toggleIcon.text('−');
                            } else {
                                // 閉じる場合: 現在のscrollHeightから0へアニメーション
                                answerContent.css('max-height', answerContent[0].scrollHeight + 'px'); // 現在の高さに設定
                                answerContent[0].offsetHeight; // 強制リフロー
                                answerContent.css('max-height', '0');
                                toggleIcon.text('+');
                            }
                        });

                    } else {
                        container.html('<p>このページに関連するFAQは見つかりませんでした。</p>');
                    }
                } else {
                    container.html('<p class="gemini-faq-error">FAQの読み込みに失敗しました: ' + (response.data ? response.data : '不明なエラー') + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                container.html('<p class="gemini-faq-error">FAQの読み込み中にエラーが発生しました。</p>');
            }
        });
    });
})(jQuery);
