# PHP_CodeSniffer Custom Standard

## 概要

- 自動修正に対応するルールには 🔧 を付けています。
- PHP_CodeSniffer (PHPCS) 用のカスタム Sniff 集です。PHP 8.2 以上を対象とし、実装は `CustomStandard/` 配下にあります。

## 使い方

プロジェクトに PHPCS とこのパッケージを追加します。

```bash
composer require --dev squizlabs/php_codesniffer gmqs/custom-standard
```

利用するルールはプロジェクト側の `phpcs.xml` または `ruleset.xml` から参照します。たとえば、次の設定でパブリックプロパティ禁止ルールを有効化できます。

```xml
<?xml version="1.0"?>
<ruleset name="Project">
    <rule ref="CustomStandard.Classes.ForbiddenPublicProperty" />
</ruleset>
```

```bash
vendor/bin/phpcs --standard=phpcs.xml src/
vendor/bin/phpcbf --standard=phpcs.xml src/
```

## ルール一覧

### Classes

- **`CustomStandard.Classes.ForbiddenPublicProperty`**: パブリックプロパティを禁止し、メソッド経由のアクセスを要求します。`{@inheritDoc}` を含むPHPDoc、または `#[\Override]` 属性が付いたプロパティは対象外です。コンストラクタのプロパティプロモーションは既定では対象外です。

- **`CustomStandard.Classes.RequireReadOnlyClass`** 🔧: インスタンスプロパティに `readonly` を要求します。static プロパティ、継承クラス、`{@inheritDoc}` / `#[\Override]` によるオーバーライドは対象外です。すでに `readonly class` の場合はプロパティ側の冗長な `readonly` を削除します。

  さらに、次の条件を満たす `final` クラスについては、クラスを `readonly` に昇格し、各プロパティの `readonly` を削除します。

  - すべてのインスタンスプロパティに型が付いている
  - static プロパティがない
  - すべてのインスタンスプロパティが `readonly`
  - 親クラスを継承していない

  `abstract`、non-final、継承中のクラス、型なしプロパティを含むクラスは、安全に変換できないためクラス昇格の対象外です。

### Commenting

- **`CustomStandard.Commenting.CommentingFormat`** 🔧: コメントの表記とインデントを統一します。

  - `#` コメントを `//` コメントに変換
  - `//` と `#` の連続コメントを PHPDoc 形式に変換
  - `/* ... */` の単一行コメントを、通常コメント・PHPDoc・構造宣言用のPHPDocに変換
  - 複数行コメントを `/**`、` * `、` */` の形式に整形
  - PHPDoc の開始・終了位置、各行のインデント、不要な先頭・末尾の空行を整形
  - プロパティの `@var` に重複しているプロパティ名を削除
  - コメントアウトされたコードはPHPDocに変換せず、`//` コメントとしてインデントと空白だけを整形

  クラス、インターフェイス、トレイト、列挙型、関数、定数などの宣言に対する説明コメントは、通常の `//` ではなくPHPDoc形式に整形されます。

- **`CustomStandard.Commenting.InheritDocToOverride`** 🔧: `@inheritDoc` または `{@inheritDoc}` を `#[\Override]` 属性へ置き換えます。PHPDocが `inheritDoc` だけの場合はPHPDoc全体を削除し、それ以外の説明がある場合は該当行だけを削除します。すでに `#[\Override]` があっても `inheritDoc` の削除は行います。

  PHPバージョンによる対象範囲は次のとおりです。`phpcs.xml` の `php_version` 設定がある場合はそれを優先し、なければ実行中のPHPバージョンを使用します。

  - PHP 8.3 未満: 無効
  - PHP 8.3 以上 8.5 未満: クラス内メソッドのみ
  - PHP 8.5 以上: クラス内メソッドとプロパティ

  コンストラクタ、クラス外の関数、クラス・インターフェイスなどの宣言は対象外です。

### Functions

- **`CustomStandard.Functions.ForbiddenDefaultArgumentValues`**: 関数、メソッド、クロージャ、アロー関数のデフォルト引数を禁止します。引数はすべて明示的に渡す必要があります。`@inheritDoc` または `#[\Override]` が付いたメソッドは対象外です。

- **`CustomStandard.Functions.ForbiddenVariadicArguments`**: 可変長引数（`...`）を禁止し、配列などでまとめて受け取ることを要求します。`@inheritDoc` または `#[\Override]` が付いたメソッドは対象外です。

- **`CustomStandard.Functions.RequireClosureArgumentTypeHint`**: クロージャとアロー関数のすべての引数に型宣言を要求します。

- **`CustomStandard.Functions.RequireClosureReturnTypeHint`**: クロージャとアロー関数に戻り値の型宣言を要求します。

### Strings

- **`CustomStandard.Strings.HeredocQuotes`**: ヒアドキュメントの識別子を `EOL` に限定します。

  ```php
  $text = <<<EOL
  text
  EOL;
  ```

- **`CustomStandard.Strings.RequireDoubleQuotes`** 🔧: シングルクォートの文字列リテラルをダブルクォートへ変換します。変換時には、ダブルクォート文字列内で意味が変わる `$` と `"` をエスケープします。複数行文字列にも対応します。

### Types

- **`CustomStandard.Types.ForbidStdClassTypeHint`** 🔧: 型宣言および対象PHPDocタグでの `stdClass` / `\stdClass` を禁止し、`object` に自動変換します。

  対象となるPHPの型宣言は、パラメータ、戻り値、プロパティです。PHPDocでは `@param`、`@return`、`@var`、`@property`、`@property-read`、`@property-write` を対象とします。

  `new stdClass()`、`instanceof stdClass`、`extends stdClass`、`implements stdClass`、名前空間付きのクラス名（例: `Foo\stdClass`）、`stdClass::` などの静的アクセスは対象外です。
