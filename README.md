# Gemini FAQ Generator (WordPress Plugin)

## 1. 概要

このWordPressプラグインは、Google Gemini APIを利用して、WordPressの投稿や固定ページの内容からFAQ（よくある質問）を自動的に生成し、ページ内に表示します。

一度FAQを生成すると、結果はWordPressのキャッシュ機能（Transients API）に保存され、2回目以降の表示は高速になります。

## 2. 必要なもの

- WordPress 5.0 以上
- PHP 7.4 以上
- Google Gemini APIキー

## 3. サーバー要件（推奨）

このプラグインは、一般的なWordPressが動作するレンタルサーバーであれば動作します。

- **OS**: Linux (WordPressが動作する環境)
- **CPU**: WordPressの推奨スペックに準拠
- **メモリ**: WordPressの推奨スペックに準拠
- **PHP実行環境**: WordPressの推奨スペックに準拠
- **ネットワーク**: GoogleのAPIサーバーへのアウトバウンドHTTPS通信が可能であること。

## 4. インストールと利用方法

### ステップ1: プラグインファイルの配置

`gemini-faq-generator` フォルダ全体を、あなたのWordPressインストールディレクトリ内の `wp-content/plugins/` ディレクトリにアップロードまたはコピーします。

配置後のディレクトリ構造の例:
```
wordpress/
└── wp-content/
    └── plugins/
        └── gemini-faq-generator/
            ├── gemini-faq-generator.php
            └── js/
                └── gemini-faq.js
```

### ステップ2: プラグインの有効化

WordPress管理画面にログインし、左メニューから「プラグイン」→「インストール済みプラグイン」へ移動します。
プラグイン一覧の中から「Gemini FAQ Generator」を見つけ、「有効化」をクリックします。

### ステップ3: Gemini APIキーの設定

プラグインを有効化すると、WordPress管理画面の左メニュー「設定」の中に「Gemini FAQ」という新しい項目が追加されます。
「設定」→「Gemini FAQ」をクリックし、設定画面に移動します。
Google AI Studioで取得したあなたのGemini APIキーを入力し、「変更を保存」ボタンをクリックします。

### ステップ4: ショートコードの利用

FAQを自動生成して表示したいWordPressの投稿や固定ページを開きます。
編集画面で、FAQを表示したい場所に以下のショートコードを挿入します。

```
[gemini_faq]
```

ページを更新または公開すると、そのページのコンテンツに基づいてGemini APIがFAQを生成し、表示されるはずです。
