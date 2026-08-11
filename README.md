# CDN Drive

CORESERVER V2 をオリジンストレージとして使い、BunnyCDN Pull Zone 経由でファイルを配信する PHP 8.2+ / SQLite 製のファイル管理・配信サービスです。

公開用 GitHub リポジトリ: https://github.com/tomosan078/rental-serv-bunny-cdndrive

Pronelt が作成したプロジェクトで、MIT License で公開しています。

ブラウザからファイルをアップロードし、実体は CORESERVER V2 上の `origin` ディレクトリに保存します。利用者へコピー・共有する配布 URL は BunnyCDN の CDN Hostname を使います。

意外と未完成な部分も多いです。レスポンシブに対応しているようで対応できていないとか。パソコンでないと設定画面が開けないとか...

MITライセンスですので自由に利用していただいても構いません(欲しい機能をAIで追加してもOK)が、このソフトウェアを利用したことにより発生した損害・責任は一切取りませんのでご了承ください。

## 概要

このアプリは、アップロードしたファイルを CORESERVER V2 の `origin` に保存し、BunnyCDN から配信するための管理画面を提供します。

## 目次

- [概要](#概要)
- [特徴](#特徴)
- [必要条件](#必要条件)
- [ディレクトリ構成](#ディレクトリ構成)
- [インストール](#インストール)
- [URL の考え方](#url-の考え方)
- [BunnyCDN 設定](#bunnycdn-設定)
- [主要機能](#主要機能)
- [WordPress 連携](#wordpress-連携)
- [トラブルシュート](#トラブルシュート)
- [バックアップ](#バックアップ)
- [ライセンス](#ライセンス)
- [復旧](#復旧)
- [制限事項](#制限事項)

## 特徴

- Composer、Node.js、Docker なしで配置可能
- PHP 8.2+ と SQLite のみで動作
- 複数ユーザー対応
- 管理者権限
- ドラッグ＆ドロップアップロード
- 複数ファイル同時アップロード
- フォルダ管理
- ファイル検索
- 画像・動画プレビュー
- ファイル名変更
- フォルダ名変更
- コピー
- 移動
- 削除
- ゴミ箱
- 復元
- 容量表示
- ストレージ使用量表示
- アップロード進捗表示
- CDN URL コピー
- QR コード生成
- 有効期限付き共有リンク
- 共有リンクの表示/ダウンロード権限
- ダークモード
- レスポンシブ対応
- 監査ログ
- 操作履歴
- Bunny API 接続確認
- Bunny Pull Zone Origin URL 同期
- Bunny Pull Zone キャッシュ削除
- CDN 開通前の Origin プレビュー確認

## 必要条件

- PHP 8.2 以上
- PDO SQLite
- JSON
- fileinfo 推奨
- cURL 推奨
- Apache `.htaccess` が有効な CORESERVER V2 環境

`/install` で PHP バージョン、PDO SQLite、JSON、書き込み権限を確認できます。

## ディレクトリ構成

```text
.
├── .htaccess
├── .user.ini
├── index.php
├── README.md
├── app/
│   └── App.php
├── assets/
│   ├── app.js
│   └── styles.css
├── data/
│   ├── .htaccess
│   ├── sessions/
│   └── trash/
└── origin/
    └── .htaccess
```

### `origin`

アップロードされたファイルの保存先です。BunnyCDN Pull Zone の Origin URL はこのディレクトリを指します。

例:

```text
https://example.com/origin
```

実ファイルは以下のようなパスに保存されます。

```text
origin/objects/u1/ab/uuid/file.jpg
```

ユーザーが作成するフォルダ構造はデータベースで管理します。物理保存パスは衝突やパストラバーサルを避けるため、UUID ベースです。

### `data`

SQLite、PHP セッション、ゴミ箱ファイルを保存します。`.htaccess` で直接アクセスを拒否します。

本番では `data` を公開ディレクトリ外に移したい場合がありますが、CORESERVER へそのままアップロードして動かす構成を優先して、この構成にしています。

## インストール

1. このディレクトリの内容を CORESERVER V2 の公開ディレクトリへアップロードします。
2. `data` と `origin` に PHP から書き込みできる権限を付与します。
3. ブラウザで `/install` にアクセスします。
4. 管理者ユーザーを作成します。
5. CDN Hostname、Origin URL、Bunny API Key、Pull Zone ID を設定します。

### 入力例

```text
管理者名:
Admin

メールアドレス:
admin@example.com

パスワード:
12文字以上

CDN Hostname:
cdn.example.com

Origin URL:
https://example.com/origin

Bunny API Key:
Bunny アカウントの API Key

Pull Zone ID:
Bunny Pull Zone の数字 ID
```

## URL の考え方

このアプリでは URL を以下のように分けます。

### Origin URL

CORESERVER V2 上の `origin` ディレクトリを直接指す URL です。

```text
https://example.com/origin
```

Origin URL は BunnyCDN がファイルを取りに行くための URL です。

### CDN Hostname

BunnyCDN の Pull Zone ドメイン、または Bunny に向けた独自 CDN ドメインです。

```text
cdn.example.com
```

アプリが利用者へ表示・コピーする配布 URL は、この CDN Hostname を使います。

### CDN URL

実際に利用者へ配布する URL です。

```text
https://cdn.example.com/objects/u1/ab/uuid/file.jpg
```

### Origin 確認 URL

CDN 開通前に管理者が確認するための URL です。

```text
https://example.com/origin/objects/u1/ab/uuid/file.jpg
```

管理画面で `プレビュー元: Origin` を選ぶと、画像・動画プレビューだけ Origin URL を使います。コピーされる配布 URL は引き続き CDN URL です。

## BunnyCDN 設定

BunnyCDN では Pull Zone を使います。

### Bunny 側の Origin URL

Bunny Pull Zone の Origin URL は CORESERVER 側の origin URL にしてください。

正しい例:

```text
https://example.com/origin
```

間違った例:

```text
https://cdn.example.com/origin
```

`cdn.example.com` が BunnyCDN の CDN Hostname の場合、それを Origin URL にすると CDN が自分自身を origin として参照する形になります。

### アプリ側の Bunny 設定

管理画面から以下を設定できます。

- API Key
- Pull Zone ID
- CDN Hostname
- Origin URL
- 最大アップロードサイズ
- プレビュー元

設定保存だけでは Bunny API へ同期しません。`保存時に Bunny Pull Zone へ同期する` にチェックを入れた時だけ、Bunny API へ Origin URL 更新を送信します。

CDN 未開通の確認中は以下がおすすめです。

```text
プレビュー元: Origin
保存時に Bunny Pull Zone へ同期する: オフ
```

CDN 開通後は以下に切り替えます。

```text
プレビュー元: CDN
保存時に Bunny Pull Zone へ同期する: 必要な時だけオン
```

## 主要機能

### ログイン

PHP セッションと SQLite の `sessions` テーブルでセッションを管理します。Cookie は HttpOnly、SameSite Strict です。

### ユーザー管理

管理者は管理画面からユーザーを作成できます。

ユーザーには以下を設定できます。

- 名前
- メールアドレス
- パスワード
- 権限
- 有効/無効
- ストレージ容量上限

### ファイルアップロード

複数ファイルを同時にアップロードできます。ドラッグ＆ドロップにも対応します。

アップロード時に以下を行います。

- ファイル名検証
- 保存パス生成
- UUID ベースの物理パス割り当て
- MIME type 検出
- SHA-256 チェックサム保存
- 容量上限チェック
- 監査ログ記録

### フォルダ管理

フォルダは SQLite の `folders` テーブルで管理します。物理ディレクトリ構造とは分離しています。

### ファイル操作

以下をサポートします。

- 名前変更
- コピー
- 移動
- 削除
- 復元
- 完全削除

削除時はファイル実体を `data/trash` へ移動します。これにより、削除済みファイルが origin から直接配信され続けることを防ぎます。

### ゴミ箱

削除されたファイルはゴミ箱に表示されます。

ゴミ箱では以下を実行できます。

- 復元
- 完全削除

### プレビュー

画像と動画をブラウザ内でプレビューできます。

プレビュー元は以下から選べます。

- CDN
- Origin

CDN 開通前は Origin を使うと、CORESERVER 側の保存状態を直接確認できます。

### CDN URL コピー

ファイル詳細から CDN URL をコピーできます。

ブラウザが Clipboard API を使えない場合は、古いコピー方式にフォールバックします。

### QR コード

ファイル詳細で CDN URL の QR コードを生成します。QR コードは PNG として保存できます。

### 共有リンク

ファイルごとに共有リンクを作成できます。

設定項目:

- 有効日数
- 表示権限
- ダウンロード権限
- 任意のパスワード

共有リンク自体はアプリの URL です。

```text
https://example.com/share/token
```

共有ページ内のファイル表示は CDN URL を使います。

### 監査ログ

以下の操作を `logs` テーブルに記録します。

- インストール
- ログイン
- ログアウト
- ログイン失敗
- アップロード
- フォルダ作成
- 名前変更
- コピー
- 移動
- 削除
- 復元
- 共有リンク作成
- 共有リンク表示
- 設定変更
- Bunny API 接続確認
- Bunny キャッシュ削除
- 保存パス修復

## データベース

SQLite を使います。

データベースファイル:

```text
data/app.sqlite
```

### `users`

ユーザー情報を保存します。

主なカラム:

- `id`
- `email`
- `name`
- `password_hash`
- `role`
- `active`
- `storage_limit_bytes`
- `created_at`
- `updated_at`
- `last_login_at`

### `folders`

フォルダ情報を保存します。

主なカラム:

- `id`
- `uuid`
- `owner_id`
- `parent_id`
- `name`
- `deleted_at`
- `created_at`
- `updated_at`

### `files`

ファイル情報を保存します。

主なカラム:

- `id`
- `uuid`
- `owner_id`
- `folder_id`
- `name`
- `relative_path`
- `mime`
- `size`
- `checksum`
- `deleted_at`
- `original_folder_id`
- `created_at`
- `updated_at`

### `shares`

共有リンク情報を保存します。

主なカラム:

- `id`
- `token`
- `owner_id`
- `file_id`
- `permission`
- `password_hash`
- `expires_at`
- `revoked_at`
- `created_at`

### `settings`

アプリ設定を保存します。

主なキー:

- `installed`
- `cdn_hostname`
- `origin_url`
- `bunny_api_key`
- `pull_zone_id`
- `preview_source`
- `max_upload_bytes`
- `app_name`

### `logs`

監査ログを保存します。

### `sessions`

ログインセッションのメタ情報を保存します。

### `rate_limits`

ログインや API のレート制限に使います。

## セキュリティ

### CSRF

POST 系 API は `X-CSRF-Token` を検証します。通常フォームでは hidden input を検証します。

### XSS 対策

PHP 側の HTML 出力は `htmlspecialchars` でエスケープします。フロント側も動的文字列は `esc()` を通します。

### SQL Injection 対策

SQLite へのアクセスは PDO prepared statement を使います。

### パス検証

ファイル名から `/`、`\`、制御文字を除去します。物理保存パスはユーザー入力をそのまま使わず、UUID ベースで生成します。

### パスワード

パスワードは `password_hash()` でハッシュ化します。

### セッション

Cookie は以下で設定します。

- HttpOnly
- SameSite Strict
- HTTPS 時は Secure

ログイン時に `session_regenerate_id(true)` を実行します。

### レート制限

ログインと API にレート制限を入れています。

### Content Security Policy

基本的に `self` を中心に制限しています。Tailwind CDN と QR ライブラリの読み込み、画像/動画プレビューに必要な範囲だけ許可しています。

## 運用手順

### CDN 開通前

1. `/install` を完了します。
2. 管理画面で `プレビュー元: Origin` にします。
3. ファイルをアップロードします。
4. ファイル詳細で `Origin確認` を開きます。
5. Origin URL で画像や動画が表示されることを確認します。

この段階で `CDNを開く` が表示できないのは正常です。

### CDN 開通後

1. Bunny Pull Zone の Origin URL を `https://example.com/origin` にします。
2. CDN Hostname を設定します。
3. Bunny 側で SSL と DNS が有効であることを確認します。
4. 管理画面で `プレビュー元: CDN` にします。
5. 必要なら `キャッシュ削除` を実行します。
6. `CDNを開く` で画像や動画が表示されることを確認します。

### 既存ファイルの保存パス修復

古いバージョンで日本語ファイル名をアップロードした場合、保存パスから拡張子が落ちている可能性があります。

管理画面の `保存パス修復` を実行すると、既存ファイルの物理パスを現在のルールに合わせて修復します。

実行後は Bunny キャッシュ削除を行ってください。

## WordPress 連携

WordPress は画像アップロード時に複数サイズを生成します。

例:

```text
wp-content/uploads/2026/07/photo.jpg
wp-content/uploads/2026/07/photo-150x150.jpg
wp-content/uploads/2026/07/photo-300x200.jpg
wp-content/uploads/2026/07/photo-1024x683.jpg
```

CDN Drive 連携では、WordPress が生成したファイル一式をそのまま `origin/wp/...` に同期します。

```text
origin/wp/2026/07/photo.jpg
origin/wp/2026/07/photo-150x150.jpg
origin/wp/2026/07/photo-300x200.jpg
origin/wp/2026/07/photo-1024x683.jpg
```

配信 URL は以下のようになります。

```text
https://cdn.example.com/wp/2026/07/photo-300x200.jpg
```

### 同梱プラグイン

WordPress 用プラグインを同梱しています。

```text
wordpress-plugin/cdn-drive-sync/cdn-drive-sync.php
```

WordPress へ設置する場合は、以下のように配置します。

```text
wp-content/plugins/cdn-drive-sync/cdn-drive-sync.php
```

WordPress 管理画面で `CDN Drive Sync` を有効化してください。

### CDN Drive 側の準備

1. CDN Drive 管理画面を開きます。
2. `WordPress連携` の `Token生成` を押します。
3. 生成された Token を控えます。
4. 表示されている Endpoint を控えます。

`Token生成` を押すと、CDN Drive 側には Token のハッシュが即時保存されます。
生の Token はこの時しか表示されないため、WordPress 側へ貼り付けてください。

Endpoint 例:

```text
https://example.com/api/external
```

### WordPress 側の設定

WordPress 管理画面の `設定 > CDN Drive Sync` で以下を設定します。

```text
CDN Drive External API:
https://example.com/api/external

API Token:
CDN Drive 管理画面で生成した Token

CDN Base URL:
https://cdn.example.com

Remote Path Prefix:
wp
```

通常は以下をオンにします。

```text
Upload/Regenerate 時に自動同期する
WordPress の画像 URL と srcset を CDN URL に置換する
```

### 同期タイミング

プラグインは以下のタイミングで CDN Drive に同期します。

- メディア追加時
- 画像メタデータ生成時
- サムネイル再生成時
- 添付ファイル削除時
- 管理画面から手動同期した時

### 既存メディアの同期

プラグインを入れる前から WordPress に存在していた画像は、自動では同期されません。

既存メディアは WordPress 管理画面の `設定 > CDN Drive Sync` から同期します。

1. `Test CDN Drive Connection` で API の疎通を確認します。
2. `Test Upload and Delete` で実際のアップロードと削除を確認します。
3. `Progress Transfer` の `Start Transfer` を押します。
4. 画面上に `転送中...` とプログレスバーが表示されます。
5. 完了するまで画面を開いたまま待ちます。
6. 中断したい場合は `Stop` を押します。

`Start Batch Sync` は、その場で 1 バッチだけ同期する手動実行用です。
通常は `Start Transfer` を使ってください。

同期済みかどうかは WordPress のメディア一覧の `CDN Drive` 列で確認できます。

個別に同期したい場合は、メディア一覧の `Sync to CDN Drive` または設定画面の `Single Attachment Sync` を使います。

同期に失敗した場合、設定画面上部の通知、設定画面内の `Last Result`、メディア一覧の `CDN Drive` 列に失敗理由が表示されます。
`WP_DEBUG` が有効な場合は、PHP error log に `[CDN Drive Sync]` で始まる詳細ログも出力されます。

画像は WordPress が生成した各サイズに加えて、`-scaled` ではないオリジナル画像も同期します。
動画は attachment の実ファイルを同期します。大きい動画向けにアップロード timeout はファイルサイズに応じて最大 1 時間まで延長されます。

### URL 置換

プラグインは以下を CDN URL に置換します。

- `wp_get_attachment_url`
- `wp_calculate_image_srcset`

これにより、WordPress の通常画像 URL と responsive image の `srcset` が CDN Drive の CDN URL になります。

### 外部 API

WordPress 連携用に以下の API を提供します。

認証は `Authorization: Bearer API_TOKEN` を基本にします。
サーバー構成で `Authorization` ヘッダーが PHP へ渡らない場合に備え、`X-CDN-Drive-Token` ヘッダー、`token` POST/GET パラメータも受け付けます。

#### 疎通確認

```text
GET /api/external/ping
Authorization: Bearer API_TOKEN
```

#### アップロード

```text
POST /api/external/upload
Authorization: Bearer API_TOKEN
X-CDN-Drive-Token: API_TOKEN
Content-Type: multipart/form-data

path=wp/2026/07/photo-300x200.jpg
token=API_TOKEN
file=@photo-300x200.jpg
```

`path` が `wp/` で始まらない場合は自動的に `wp/` が付与されます。

#### 削除

```text
POST /api/external/delete
Authorization: Bearer API_TOKEN
Content-Type: application/json

{
  "paths": [
    "wp/2026/07/photo.jpg",
    "wp/2026/07/photo-300x200.jpg"
  ]
}
```

#### キャッシュ削除

```text
POST /api/external/purge
Authorization: Bearer API_TOKEN
```

### WordPress 連携の設計方針

画像リサイズは WordPress に任せます。CDN Drive はリサイズ済みファイルを保存・配信するだけです。

この設計にすると、WordPress の既存の画像サイズ、カスタム画像サイズ、`srcset`、サムネイル再生成プラグインと相性よく動きます。

### WordPress 連携の注意点

- WordPress 側 PHP に cURL が必要です。
- Token は WordPress 側では送信用に平文保存されます。
- CDN Drive 側では Token はハッシュ保存されます。
- 既存メディアは `Start Transfer` で同期してください。
- URL 置換をオンにする前に、既存メディアの同期を完了してください。
- CDN 未開通中は CDN URL 置換をオフにして動作確認してください。
- CDN 開通後に URL 置換をオンにしてください。
- WordPress と CDN Drive が別ドメインの場合、SSL 証明書と mixed content に注意してください。

## トラブルシュート

### `/install` で PDO SQLite が NG

PHP の SQLite PDO ドライバが有効ではありません。CORESERVER の PHP 設定を確認してください。

### `入力値が正しくありません` が出る

以下を確認してください。

- 管理者名が空ではない
- メールアドレスが正しい形式
- パスワードが 12 文字以上
- CDN Hostname が `cdn.example.com` のようなホスト名だけ
- Origin URL が `https://example.com/origin` のような URL
- Pull Zone ID が数字

### Origin 確認は表示されるが CDN 確認は表示されない

CDN 未開通なら正常です。Bunny Pull Zone、DNS、SSL、Origin URL を設定してから CDN 確認をしてください。

### CDN 確認で 404

以下を確認してください。

- Bunny Pull Zone の Origin URL が正しい
- Origin URL に `/origin` が含まれている
- CDN URL のパスが `/objects/...` になっている
- ファイルが削除済みではない
- Bunny キャッシュを削除した

### CDN 確認で 403

以下を確認してください。

- CORESERVER 側で `origin` 配下にアクセス制限をかけていない
- Bunny 側のアクセス制限設定
- Hotlink protection
- Token authentication
- SSL 証明書

### CDN 確認で 405

以下を確認してください。

- CDN Hostname を Origin URL に設定していない
- Bunny API の Pull Zone ID が正しい
- Bunny API Key が正しい
- 設定保存時に不要な Bunny 同期をオンにしていない

CDN 未開通の確認中は `保存時に Bunny Pull Zone へ同期する` をオフにしてください。

### 画像が表示されない

以下を確認してください。

- `プレビュー元: Origin` で表示されるか
- `Origin確認` の URL を直接開けるか
- 画像ファイルの拡張子が保存パスに残っているか
- `保存パス修復` を実行したか
- ブラウザの mixed content で止まっていないか

管理画面を HTTPS で開いている場合、Origin URL も HTTPS にしてください。

### WordPress の既存メディアが同期されない

以下の順で確認してください。

1. WordPress 管理画面の `設定 > CDN Drive Sync` で設定を保存します。
2. `Test CDN Drive Connection` が成功することを確認します。
3. `Test Upload and Delete` が成功することを確認します。
4. `Start Transfer` を実行します。
5. プログレス画面のログ、設定画面内の `Last Result`、メディア一覧の `CDN Drive` 列で失敗理由を確認します。
6. `WP_DEBUG` が有効な場合は PHP error log の `[CDN Drive Sync]` を確認します。

`Test CDN Drive Connection` は成功するのに `Test Upload and Delete` が失敗する場合は、CDN Drive の API URL、API Token、PHP cURL、アップロード上限、WAF、Basic 認証、SSL 証明書を確認してください。

`External API token is not configured.` と表示される場合は、CDN Drive 側で WordPress 連携 Token が保存されていません。
CDN Drive の管理画面で `Token生成` をもう一度押し、生成された Token を WordPress 側の `API Token` に貼り直してください。

### JavaScript が古いまま動く

ブラウザキャッシュを強制リロードしてください。

```text
Windows: Ctrl + F5
Mac: Cmd + Shift + R
```

## バックアップ

最低限、以下をバックアップしてください。

```text
data/app.sqlite
origin/
data/trash/
```

SQLite と `origin` の整合性が重要です。同じタイミングでバックアップすることを推奨します。

## ライセンス

このリポジトリは MIT License です。Copyright (c) 2026 Pronelt。

## 復旧

1. ファイル一式を配置します。
2. `data/app.sqlite` を戻します。
3. `origin` を戻します。
4. `data/trash` を戻します。
5. 書き込み権限を確認します。
6. 管理画面で Bunny 設定を確認します。
7. 必要なら Bunny キャッシュ削除を実行します。

## 制限事項

- 外部ストレージは使いません。
- ファイル実体は CORESERVER V2 のディスク容量に依存します。
- 大容量ファイルは PHP と Web サーバーのアップロード上限に依存します。
- CDN URL は公開配信用 URL です。秘匿ファイルを扱う場合は Bunny の Token Authentication などを併用してください。
- 共有リンクの有効期限はアプリの共有ページに適用されます。CDN URL 自体を受け取った相手のアクセス制御は Bunny 側の設定に依存します。

## 推奨設定

### CDN 開通前

```text
プレビュー元: Origin
保存時に Bunny Pull Zone へ同期する: オフ
```

### CDN 開通後

```text
プレビュー元: CDN
保存時に Bunny Pull Zone へ同期する: 必要な時だけオン
```

### Bunny Pull Zone

```text
Origin URL:
https://example.com/origin

CDN Hostname:
cdn.example.com
```

CDN Hostname を Origin URL に設定しないでください。
