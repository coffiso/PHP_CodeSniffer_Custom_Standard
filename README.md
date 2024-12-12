# PHP_CodeSniffer_Custom_Standard
php_codesnifferの拡張ルール

## ルール一覧
🔧 = エラーの自動修正に対応

## Functions
### CustomStandard.Functions.RequireClosureArgumentTypeHint
無名関数の引数に型ヒントがない場合に指摘します。
### CustomStandard.Functions.RequireClosureReturnTypeHint
無名関数の戻り値に型ヒントがない場合に指摘します。
### CustomStandard.Functions.ForbiddenVariadicArguments
可変長引数を禁止します。
### CustomStandard.Functions.ForbiddenDefaultArgumentValues
デフォルト引数を禁止します。

## Strings
### CustomStandard.Strings.RequireDoubleQuotes 🔧
文字列リテラルの引用符をダブルクォート「"」に強制します。

### CustomStandard.Strings.HeredocQuotes
ヒアドキュメントの引用符を`EOL`に制限します。

## Classes
### CustomStandard.Classes.RequireReadOnlyProperties
読み取り専用インスタンスプロパティを必須にします。

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
