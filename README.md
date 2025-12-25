# PHP_CodeSniffer_Custom_Standard
php_codesnifferの拡張ルール

## ルール一覧
🔧 = エラーの自動修正に対応

## Functions
### CustomStandard.Functions.RequireClosureArgumentTypeHint
無名関数・アロー関数の引数に型ヒントがない場合に指摘します。
### CustomStandard.Functions.RequireClosureReturnTypeHint
無名関数・アロー関数の戻り値に型ヒントがない場合に指摘します。
### CustomStandard.Functions.ForbiddenVariadicArguments
関数・メソッド・無名関数・アロー関数の可変長引数（`...`）を禁止します。配列での受け取りを要求します。

**例外**: `@inheritdoc`アノテーションが付いているメソッドは除外されます。
### CustomStandard.Functions.ForbiddenDefaultArgumentValues
関数・メソッド・無名関数・アロー関数のデフォルト引数を禁止します。全ての引数は明示的に渡す必要があります。

**例外**: `@inheritdoc`アノテーションが付いているメソッドは除外されます。

## Strings
### CustomStandard.Strings.RequireDoubleQuotes 🔧
文字列リテラルの引用符をダブルクォート「"」に強制します。

### CustomStandard.Strings.HeredocQuotes
ヒアドキュメントの引用符を`EOL`に制限します。

## Classes
### CustomStandard.Classes.RequireReadOnlyClass 🔧
読み取り専用クラスを原則必須にします。

## TypeHints
### CustomStandard.TypeHints.ForbiddenTypeUsage
使用禁止クラス・インターフェースの型使用を検出します。

設定可能な `forbiddenTypes` プロパティで禁止する型を指定します。以下の場面での禁止型使用を検出します：
- 関数・メソッドの引数型宣言
- 関数・メソッドの戻り値型宣言
- プロパティの型宣言
- Union型・Intersection型での使用
- クロージャ・Arrow関数での型使用
- インターフェース・抽象クラスでの型宣言

設定例（ruleset.xmlで使用）:
```xml
<rule ref="CustomStandard.TypeHints.ForbiddenTypeUsage">
    <properties>
        <property name="forbiddenTypes" type="array">
            <element key="DateTime" value="DateTimeインターフェースを使用してください"/>
            <element key="stdClass" value="stdClassの使用は禁止されています"/>
        </property>
    </properties>
</rule>
```

## Commenting
### CustomStandard.Commenting.CommentingFormat 🔧
コメントの統一フォーマットを強制します。

例:
### ❌️`#`の開始タグを使用しないでください 🔧
```php
# comment
```
### ❌️開始タグの後は半角スペースを1つ開けてください 🔧
```php
//comment
```
### ❌️複数行にまたがってインラインコメントを使用しないでください 🔧
```php
// comment
// comment
```
### ❌️閉じタグ付きインラインコメントの書式は使用しないでください 🔧
```php
/* comment */
```
### ❌️開始タグの行には何も書かないでください 🔧
```php
/* comment
 * 
 */
```
### ❌️無意味な空行を作らないでください 🔧
```php
/* 
 *
 * comment
 * 
 */
```
### ❌️スターのインデントは統一してください 🔧
```php
/* 
 * comment
* comment
  * comment
 */
```
### ❌️スターの後は少なくとも1つ以上の半角スペースを開けてください 🔧
```php
/* 
 *comment
 */
```
### ❌️終了タグの行には何も書かないでください 🔧
```php
/*
 * 
comment */
```
### ❌️1行しか本文の無いコメントに対して複数行書式を使用しないでください 🔧
```php
/*
 * comment
 */
```
```php
/**
 * @var string $name 名前
 */
```
### ❌️アノテーションの存在しないPHPDoc形式のコメントは使用しないでください 🔧
```php
/**
 * comment
 * comment
 */
```
