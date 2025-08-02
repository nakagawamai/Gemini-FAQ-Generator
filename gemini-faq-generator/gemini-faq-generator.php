<?php
/**
 * Plugin Name: Gemini FAQ Generator
 * Plugin URI:  https://github.com/nakagawamai/Gemini-FAQ-Generator
 * Description: Automatically generates FAQs for your WordPress posts/pages using Google Gemini API.
 * Version:     1.0.0
 * Author:      Mai Nakagawa
 * Author URI:  https://mai.kosodante.com
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// プラグインのアクティベーション時に実行される関数
function gemini_faq_generator_activate() {
    // キャッシュバージョンの初期値を設定
    if ( false === get_option( 'gemini_faq_prompt_version' ) ) {
        add_option( 'gemini_faq_prompt_version', time() );
    }
}
register_activation_hook( __FILE__, 'gemini_faq_generator_activate' );

// プラグインのディアクティベーション時に実行される関数
function gemini_faq_generator_deactivate() {
    // 将来的に必要なクリーンアップがあればここに記述
}
register_deactivation_hook( __FILE__, 'gemini_faq_generator_deactivate' );

// ショートコードの登録
function gemini_faq_shortcode( $atts ) {
    global $post;
    $post_id = isset( $post->ID ) ? $post->ID : 0;
    $output = '<h3>FAQ</h3>';    // まず、手動で編集・保存されたFAQコンテンツを試す
    $manual_faq_content = get_post_meta( $post_id, '_gemini_faq_content', true );
    if ( ! empty( $manual_faq_content ) ) {        // テキストをパースしてHTMLを生成        
        $faqs = gemini_faq_parse_text_to_array( $manual_faq_content );
        if ( ! empty( $faqs ) ) {
            $output .= gemini_faq_render_html( $faqs );
            // JSON-LDも生成して出力
            $output .= gemini_faq_generate_json_ld( $faqs );
            return $output;        
        }    
    }    // 手動のFAQがない場合、従来通りAJAXで読み込む
    $output .= '<div id="gemini-faq-container" data-post-id="' . esc_attr( $post_id ) . '">FAQを読み込み中...</div>';
    // 構造化データ (JSON-LD) を出力
    $json_ld = get_post_meta( $post_id, '_gemini_faq_json_ld', true );
    if ( ! empty( $json_ld ) ) {
        $output .= '<script type="application/ld+json">' . $json_ld . '</script>';
    }
    return $output;
}

// テキストをFAQ配列にパースするヘルパー関数
function gemini_faq_parse_text_to_array( $text ) {
    $faqs = array();
    $lines = explode( "\n", trim( $text ) );
    $current_question = '';
    $current_answer = '';
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;
        if ( strpos( $line, 'Q:' ) === 0 ) {
            if ( ! empty( $current_question ) ) {
                $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
            }
            $current_question = substr( $line, 2 );
            $current_answer = '';
        } elseif ( strpos( $line, 'A:' ) === 0 ) {
                $current_answer .= substr( $line, 2 );
        } else {
            $current_answer .= ' ' . $line;
        }    
    }
    
    if ( ! empty( $current_question ) ) {
        $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
    }
    
    return $faqs;
}

// FAQ配列をHTMLにレンダリングするヘルパー関数
function gemini_faq_render_html( $faqs ) {
    $html = '';    
    foreach ( $faqs as $faq ) {
        $html .= '<details class="gemini-faq-item">'; // 各項目をdetailsで囲む
        $html .= '<summary class="gemini-faq-question">' . esc_html( $faq['question'] ) . '</summary>';
        $html .= '<div class="gemini-faq-answer"><p>' . nl2br( esc_html( $faq['answer'] ) ) . '</p></div>'; // 回答をdivで囲む
        $html .= '</details>';    
    }
    return $html;
}
            
// FAQ配列からJSON-LDを生成するヘルパー関数
function gemini_faq_generate_json_ld( $faqs ) {
    $json_ld_data = array(
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array(),    
    );
    
    foreach ( $faqs as $faq ) {
        $json_ld_data['mainEntity'][] = array(
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ),
        );    
    }    
    
    return '<script type="application/ld+json">' . json_encode( $json_ld_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}

add_shortcode( 'gemini_faq', 'gemini_faq_shortcode' );

// JavaScriptファイルの読み込み
function gemini_faq_enqueue_scripts() {
    wp_enqueue_style(
        'gemini-faq-style',
        plugins_url( 'css/gemini-faq.css', __FILE__ ),
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'gemini-faq-script',
        plugins_url( 'js/gemini-faq.js', __FILE__ ),
        array( 'jquery' ),
        '1.0.0',
        true // フッターで読み込む
    );

    // AJAX URLをJavaScriptに渡す
    wp_localize_script(
        'gemini-faq-script',
        'geminiFaqAjax',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gemini_faq_nonce' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'gemini_faq_enqueue_scripts' );

// AJAXリクエストのハンドリング
/**
 * 指定されたURLのコンテンツからFAQを生成し、キャッシュするヘルパー関数
 * @param string $current_page_url FAQを生成する元のURL
 * @param int $post_id 関連する投稿ID
 * @return array|false 生成されたFAQの配列、または失敗した場合はfalse
 */
function _gemini_faq_generate_and_cache_for_url($current_page_url, $post_id) {
    $api_key = get_option( 'gemini_faq_api_key' );
    if ( empty( $api_key ) ) {
        error_log( 'Gemini FAQ: API Key is not set.' );
        return false;
    }

    // キャッシュキーの生成 (URL、投稿ID、プロンプトバージョン、記事ごとの設定を組み合わせる)
    $prompt_version = get_option( 'gemini_faq_prompt_version', time() );
    $post_prompt_setting = get_post_meta( $post_id, '_gemini_faq_prompt_select', true );
    $cache_key = 'gemini_faq_' . md5( $current_page_url . $post_id . $prompt_version . $post_prompt_setting );
    $cache_duration_days = get_option( 'gemini_faq_cache_duration', 1 ); // 設定値を取得、なければ1日

    // キャッシュがある場合はそれを返す
    $cached_data = get_transient( $cache_key );
    if ( false !== $cached_data && is_array($cached_data) && isset($cached_data['faqs']) && is_array($cached_data['faqs']) && isset($cached_data['json_ld']) ) {
        error_log('Gemini FAQ: Cache hit for ' . $current_page_url);
        return $cached_data;
    }

    error_log('Gemini FAQ: Cache miss for ' . $current_page_url . '. Generating new FAQ.');

    // キャッシュがない場合、コンテンツを取得してGemini APIを呼び出す
    $html_content = wp_remote_retrieve_body( wp_remote_get( $current_page_url, array( 'timeout' => 10 ) ) );

    if ( is_wp_error( $html_content ) || empty( $html_content ) ) {
        error_log( 'Gemini FAQ: Failed to fetch page content for ' . $current_page_url );
        return false;
    }

    // HTMLからテキストを抽出 (簡易版)
    $text_content = strip_tags( $html_content );
    $text_content = preg_replace( '/\s+/', ' ', $text_content ); // 複数の空白を単一の空白に
    $text_content = mb_substr( $text_content, 0, 5000 ); // Gemini APIのトークン制限を考慮し、最大5000文字に制限

    if ( empty( $text_content ) ) {
        error_log( 'Gemini FAQ: Could not extract text from the page ' . $current_page_url );
        return false;
    }

    // プロンプトの選択（記事ごとの設定を優先）
    $selected_prompt_key = get_post_meta( $post_id, '_gemini_faq_prompt_select', true );
    if ( empty( $selected_prompt_key ) || $selected_prompt_key === 'site_default' ) {
        $selected_prompt_key = get_option( 'gemini_faq_prompt_select', 'default' );
    }

    $prompts = gemini_faq_get_prompts();
    $prompt_text = isset($prompts[$selected_prompt_key]) ? $prompts[$selected_prompt_key] : $prompts['default'];

    // Gemini API呼び出し
    $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;
    $headers = array(
        'Content-Type' => 'application/json',
    );
    $body = json_encode(array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => $prompt_text . "\n\n---\n" . $text_content . "\n---"),
                ),
            ),
        ),
    ));

    $response = wp_remote_post( $gemini_url, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30, // タイムアウトを長めに設定
    ));

    if ( is_wp_error( $response ) ) {
        error_log( 'Gemini FAQ: API request failed: ' . $response->get_error_message() );
        return false;
    }

    $response_body = wp_remote_retrieve_body( $response );
    $gemini_data = json_decode( $response_body, true );

    if ( ! isset( $gemini_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
        error_log( 'Gemini FAQ: Failed to parse Gemini API response for ' . $current_page_url );
        return false;
    }

    $gemini_text = $gemini_data['candidates'][0]['content']['parts'][0]['text'];

    // GeminiのテキストをFAQ配列にパース
    $faqs = array();
    $lines = explode( "\n", $gemini_text );
    $current_question = '';
    $current_answer = '';

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;

        if ( strpos( $line, 'Q:' ) === 0 ) {
            if ( ! empty( $current_question ) ) {
                $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
            }
            $current_question = substr( $line, 2 );
            $current_answer = '';
        } elseif ( strpos( $line, 'A:' ) === 0 ) {
            $current_answer .= substr( $line, 2 );
        } else {
            // 回答の続き
            $current_answer .= ' ' . $line;
        }
    }
    if ( ! empty( $current_question ) ) {
        $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
    }

    if ( empty( $faqs ) ) {
        error_log( 'Gemini FAQ: No FAQ content could be generated from Gemini for ' . $current_page_url );
        return false;
    }

    // JSON-LD (FAQPage) 構造化データを生成
    $json_ld_data = array(
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array(),
    );

    foreach ( $faqs as $faq ) {
        $json_ld_data['mainEntity'][] = array(
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ),
        );
    }
    $json_ld_output = json_encode( $json_ld_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

    // キャッシュに保存
    set_transient( $cache_key, array('faqs' => $faqs, 'json_ld' => $json_ld_output), $cache_duration_days * DAY_IN_SECONDS );

    // 投稿メタデータにも保存（編集用）
    update_post_meta( $post_id, '_gemini_faq_content', $gemini_text );

    return array('faqs' => $faqs, 'json_ld' => $json_ld_output);
}

function gemini_faq_ajax_handler() {
    check_ajax_referer( 'gemini_faq_nonce', 'nonce' );

    $post_id = intval( $_POST['post_id'] );
    $current_page_url = sanitize_url( $_POST['current_page_url'] );

    error_log('Gemini FAQ AJAX: Received post_id = ' . $post_id . ', current_page_url = ' . $current_page_url);

    // post_idが0の場合、URLから投稿IDを推測する
    if ( $post_id === 0 && ! empty( $current_page_url ) ) {
        $inferred_post_id = url_to_postid( $current_page_url );
        if ( $inferred_post_id > 0 ) {
            $post_id = $inferred_post_id;
            error_log('Gemini FAQ AJAX: Inferred post_id from URL: ' . $post_id);
        }
    }

    if ( $post_id === 0 ) { // 推測後も0の場合
        wp_send_json_error( 'Invalid request data: Post ID missing or zero, and could not be inferred from URL.' );
    }
    if ( ! $current_page_url ) {
        wp_send_json_error( 'Invalid request data: Current page URL missing.' );
    }

    // キャッシュから取得、または生成
    $result = _gemini_faq_generate_and_cache_for_url($current_page_url, $post_id);

    if ( false === $result || !isset($result['faqs']) || !is_array($result['faqs']) ) {
        wp_send_json_error( 'Failed to generate or retrieve FAQ data.' );
    }

    wp_send_json_success( $result['faqs'] );
}
add_action( 'wp_ajax_gemini_faq', 'gemini_faq_ajax_handler' );
add_action( 'wp_ajax_nopriv_gemini_faq', 'gemini_faq_ajax_handler' );

/**
 * 投稿が保存されたときにFAQを生成・キャッシュする
 * @param int $post_id 投稿ID
 * @param WP_Post $post 投稿オブジェクト
 * @param bool $update 投稿が更新された場合はtrue、新規作成の場合はfalse
 */
function gemini_faq_generate_on_post_save( $post_id, $post, $update ) {
    // 自動保存、リビジョン、またはクイック編集の場合は処理しない
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['_inline_edit'] ) ) return; // クイック編集

    // 投稿タイプが公開されているものか確認 (例: post, page)
    $post_type = get_post_type( $post_id );
    $public_post_types = apply_filters( 'gemini_faq_public_post_types', array( 'post', 'page' ) );
    if ( ! in_array( $post_type, $public_post_types ) ) return;

    // 投稿コンテンツにショートコードが含まれているか確認
    if ( has_shortcode( $post->post_content, 'gemini_faq' ) ) {
        $current_page_url = get_permalink( $post_id );
        if ( ! $current_page_url ) {
            error_log( 'Gemini FAQ: Could not get permalink for post ID ' . $post_id );
            return;
        }

        error_log( 'Gemini FAQ: Triggering FAQ generation on post save for ' . $current_page_url );
        // FAQを生成し、キャッシュに保存
        $result = _gemini_faq_generate_and_cache_for_url( $current_page_url, $post_id );

        if ( $result && isset( $result['json_ld'] ) ) {
            update_post_meta( $post_id, '_gemini_faq_json_ld', $result['json_ld'] );
        } else {
            delete_post_meta( $post_id, '_gemini_faq_json_ld' ); // 生成失敗時は削除
        }
    }
}
add_action( 'save_post', 'gemini_faq_generate_on_post_save', 10, 3 );

// 投稿ページにメタボックスを追加 (プロンプト設定とエディタ)
function gemini_faq_add_meta_boxes() {
    $public_post_types = apply_filters( 'gemini_faq_public_post_types', array( 'post', 'page' ) );

    // プロンプト設定メタボックス
    add_meta_box(
        'gemini_faq_prompt_settings',
        'Gemini FAQ プロンプト設定',
        'gemini_faq_prompt_meta_box_callback',
        $public_post_types,
        'side',
        'default'
    );

    // FAQエディタメタボックス
    add_meta_box(
        'gemini_faq_editor',
        'Gemini FAQ Editor',
        'gemini_faq_editor_meta_box_callback',
        $public_post_types,
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'gemini_faq_add_meta_boxes' );

// プロンプト設定メタボックスのコンテンツを表示
function gemini_faq_prompt_meta_box_callback( $post ) {
    wp_nonce_field( 'gemini_faq_save_meta_box_data', 'gemini_faq_meta_box_nonce' );

    $selected_prompt = get_post_meta( $post->ID, '_gemini_faq_prompt_select', true );
    if ( empty( $selected_prompt ) ) {
        $selected_prompt = 'site_default';
    }

    $prompts = gemini_faq_get_prompts();
    $prompt_titles = array(
        'default' => '標準',
        'professional' => '専門家風',
        'beginner' => '初心者向け',
        'seo' => 'SEOライク',
    );

    echo '<p>この記事のFAQを生成する際のプロンプトを選択します。</p>';
    echo '<select name="gemini_faq_prompt_select_per_post" id="gemini_faq_prompt_select_per_post" style="width:100%;">';
    
    $site_default_prompt_key = get_option('gemini_faq_prompt_select', 'default');
    $site_default_prompt_title = isset($prompt_titles[$site_default_prompt_key]) ? $prompt_titles[$site_default_prompt_key] : ucfirst($site_default_prompt_key);
    echo '<option value="site_default"' . selected( $selected_prompt, 'site_default', false ) . '>サイトのデフォルト設定 (' . esc_html($site_default_prompt_title) . ')</option>';

    foreach ( $prompts as $key => $prompt_text ) {
        $title = isset($prompt_titles[$key]) ? $prompt_titles[$key] : ucfirst($key);
        echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected_prompt, $key, false ) . '>' . esc_html( $title ) . '</option>';
    }
    echo '</select>';
}

// FAQ編集メタボックスのコンテンツを表示
function gemini_faq_editor_meta_box_callback( $post ) {
    $faq_content = get_post_meta( $post->ID, '_gemini_faq_content', true );

    echo '<p>AIが生成したFAQはここに表示され、手動で編集・保存できます。</p>';
    echo '<textarea name="gemini_faq_content" id="gemini_faq_content_textarea" style="width:100%; height:250px;">' . esc_textarea( $faq_content ) . '</textarea>';
    echo '<div style="margin-top:10px;">';
    echo '<button type="button" id="gemini_faq_regenerate_button" class="button">FAQを再生成する</button>';
    echo '<span id="gemini_faq_spinner" class="spinner" style="float:none; margin-left: 5px;"></span>';
    echo '</div>';
    echo '<p class="description">注意: 「FAQを再生成する」ボタンを押すと、現在の編集内容は破棄され、新しいFAQが生成されます。</p>';
}

// 両方のメタボックスのデータを保存
function gemini_faq_save_meta_box_data( $post_id ) {
    // ノンスを検証
    if ( ! isset( $_POST['gemini_faq_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['gemini_faq_meta_box_nonce'], 'gemini_faq_save_meta_box_data' ) ) {
        return;
    }

    // 自動保存の場合は何もしない
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // ユーザー権限をチェック
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // プロンプト設定を保存
    if ( isset( $_POST['gemini_faq_prompt_select_per_post'] ) ) {
        $prompt_data = sanitize_key( $_POST['gemini_faq_prompt_select_per_post'] );
        update_post_meta( $post_id, '_gemini_faq_prompt_select', $prompt_data );
    }

    // FAQ編集内容を保存
    if ( isset( $_POST['gemini_faq_content'] ) ) {
        // textareaの内容をサニタイズ
        $faq_data = sanitize_textarea_field( $_POST['gemini_faq_content'] );
        update_post_meta( $post_id, '_gemini_faq_content', $faq_data );
    }
}
add_action( 'save_post', 'gemini_faq_save_meta_box_data' );

// 管理画面用のスクリプトとAJAXハンドラ
function gemini_faq_admin_enqueue_scripts($hook) {
    // 投稿の新規作成または編集ページでのみ動作
    if ( 'post.php' != $hook && 'post-new.php' != $hook ) {
        return;
    }

    global $post;
    $post_id = isset($post->ID) ? $post->ID : 0;

    // メインのJSファイルを読み込む
    wp_enqueue_script(
        'gemini-faq-admin-script',
        plugins_url( 'js/gemini-faq.js', __FILE__ ),
        array( 'jquery' ),
        filemtime(plugin_dir_path(__FILE__) . 'js/gemini-faq.js'),
        true
    );

    // AJAX用のデータをJavaScriptに渡す
    wp_localize_script(
        'gemini-faq-admin-script',
        'geminiFaqAdminAjax',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gemini_faq_regenerate_nonce' ),
            'post_id'  => isset($post->ID) ? $post->ID : 0,
        )
    );
}
add_action( 'admin_enqueue_scripts', 'gemini_faq_admin_enqueue_scripts' );

// FAQ再生成用のAJAXハンドラ
function gemini_faq_ajax_regenerate_handler() {
    check_ajax_referer( 'gemini_faq_regenerate_nonce', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( '権限がありません。', 403 );
    }
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    if ( $post_id === 0 ) {
        wp_send_json_error( '無効な投稿IDです。' );
    }
    $current_page_url = get_permalink( $post_id );
    if ( ! $current_page_url ) {
        wp_send_json_error( '投稿のパーマリンクが取得できませんでした。' );
    }

    // 既存のキャッシュを削除して再生成を強制
    $prompt_version = get_option( 'gemini_faq_prompt_version', time() );
    $post_prompt_setting = get_post_meta( $post_id, '_gemini_faq_prompt_select', true );
    $cache_key = 'gemini_faq_' . md5( $current_page_url . $post_id . $prompt_version . $post_prompt_setting );
    delete_transient($cache_key);

    // FAQを生成（この関数は内部でキャッシュと投稿メタを更新する）
    _gemini_faq_generate_and_cache_for_url( $current_page_url, $post_id );
    $new_faq_content = get_post_meta( $post_id, '_gemini_faq_content', true );
    if ( empty( $new_faq_content ) ) {
        wp_send_json_error( '生成されたFAQコンテンツの取得に失敗しました。' );
    }
    wp_send_json_success( array( 'faq_content' => $new_faq_content ) );
}
add_action( 'wp_ajax_gemini_faq_regenerate', 'gemini_faq_ajax_regenerate_handler' );

// プラグイン設定ページ（APIキー入力用）
function gemini_faq_settings_page() {
    add_options_page(
        'Gemini FAQ Settings',
        'Gemini FAQ',
        'manage_options',
        'gemini-faq-settings',
        'gemini_faq_settings_page_content'
    );
}
add_action( 'admin_menu', 'gemini_faq_settings_page' );

function gemini_faq_settings_page_content() {
    // 設定ページのHTMLを記述
    ?>
    <div class="wrap">
        <h1>Gemini FAQ Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'gemini_faq_options_group' );
            do_settings_sections( 'gemini-faq-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function gemini_faq_get_prompts() {
    $common_instruction = '各ペアは、必ず"Q: "で始まる質問と"A: "で始まる回答の形式にしてください。';
    return array(
        'default'      => '以下のテキストに基づいて、想定される質問と回答のペアを5つ作成してください。' . $common_instruction,
        'professional' => '以下の記事を専門家の視点から分析し、読者が抱くであろう重要な質問と、それに対する明確かつ簡潔な回答を5組生成してください。' . $common_instruction,
        'beginner'     => 'この記事の内容を初めて読む人でも理解できるように、基本的な質問と簡単な言葉での回答を5ペア作成してください。' . $common_instruction,
        'seo'          => '以下のテキストのSEOを意識し、検索エンジンで上位表示されやすいような、具体的なキーワードを含んだ質問と回答のペアを5つ生成してください。' . $common_instruction,
    );
}

function gemini_faq_register_settings() {
    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_api_key',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );

    add_settings_section(
        'gemini_faq_api_section',
        'Gemini API Settings',
        null,
        'gemini-faq-settings'
    );

    add_settings_field(
        'gemini_faq_api_key_field',
        'Gemini API Key',
        'gemini_faq_api_key_callback',
        'gemini-faq-settings',
        'gemini_faq_api_section'
    );

    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_cache_duration',
        array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1, // デフォルトは1日
        )
    );

    add_settings_field(
        'gemini_faq_cache_duration_field',
        'FAQキャッシュ期間 (日数)',
        'gemini_faq_cache_duration_callback',
        'gemini-faq-settings',
        'gemini_faq_api_section'
    );

    // プロンプト選択設定
    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_prompt_select',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default' => 'default',
            'show_in_rest' => true, // REST APIで利用可能に
            'description' => 'The selected prompt for FAQ generation.',
        )
    );

    add_settings_field(
        'gemini_faq_prompt_select_field',
        '生成プロンプトの選択',
        'gemini_faq_prompt_select_callback',
        'gemini-faq-settings',
        'gemini_faq_api_section'
    );

    // プロンプトバージョン管理
    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_prompt_version',
        array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => time(),
        )
    );

    // プロンプト設定が更新されたら、バージョンを更新する
    add_action('update_option_gemini_faq_prompt_select', function($old_value, $value) {
        if ($old_value !== $value) {
            update_option('gemini_faq_prompt_version', time());
        }
    }, 10, 2);
}
add_action( 'admin_init', 'gemini_faq_register_settings' );

function gemini_faq_api_key_callback() {
    $api_key = get_option( 'gemini_faq_api_key' );
    echo '<input type="text" name="gemini_faq_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
}

function gemini_faq_cache_duration_callback() {
    $cache_duration = get_option( 'gemini_faq_cache_duration', 1 ); // デフォルトは1日
    echo '<input type="number" name="gemini_faq_cache_duration" value="' . esc_attr( $cache_duration ) . '" class="small-text" min="1" /> 日';
}

function gemini_faq_prompt_select_callback() {
    $prompts = gemini_faq_get_prompts();
    $selected_prompt = get_option( 'gemini_faq_prompt_select', 'default' );
    $prompt_titles = array(
        'default' => '標準',
        'professional' => '専門家風',
        'beginner' => '初心者向け',
        'seo' => 'SEOライク',
    );

    echo '<select name="gemini_faq_prompt_select">';
    foreach ( $prompts as $key => $prompt_text ) {
        $title = isset($prompt_titles[$key]) ? $prompt_titles[$key] : ucfirst($key);
        echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected_prompt, $key, false ) . '>' . esc_html( $title ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">FAQを生成する際に使用するプロンプトのスタイルを選択します。</p>';
}
