# PHP_CodeSniffer_Custom_Standard
php_codesnifferの拡張ルール

## ルール一覧
🔧 = エラーの自動修正に対応

## Functions
### CustomStandard.Functions.RequireClosureArgumentTypeHint
無名関数の引数に型ヒントがない場合に指摘します。
### CustomStandard.Functions.RequireClosureReturnTypeHint
無名関数の戻り値に型ヒントがない場合に指摘します。

## Strings
### CustomStandard.Strings.RequireDoubleQuotes 🔧
文字列リテラルの引用符をダブルクォート「"」に強制します。

### CustomStandard.Strings.HeredocQuotes
ヒアドキュメントの引用符を`EOL`に制限します。

## Classes
### CustomStandard.Classes.ConstructorPropertyPromotion 🔧
コンストラクタプロパティプロモーションに関するルール。
- 部分的な昇格を禁止
- すべてのプロパティが昇格可能な場合は全て昇格させる
- 部分的に昇格できないプロパティが存在する場合は全てのプロパティの昇格を禁止

**サンプル**

### ❌全てのプロパティは昇格可能なので昇格させなければならない。
```php
class A {
    
    private readonly int $b;
    public function __construct(private readonly int $a, int $b) {
        $this->b = $b; //昇格可能
    }
}
```

### ❌一部のプロパティが昇格不可能なので全てのプロパティは昇格させてはならない。
```php
class A {
    
    private readonly int $b;
    public function __construct(private readonly int $a, int $b) {
        $this->b = $b + 1; //昇格不能
    }
}
```

### 🙆‍♀一部のプロパティが昇格不可能なので全てのプロパティに明示的に代入。
```php
class A {
    
    private readonly int $a;
    private readonly int $b;
    public function __construct(int $a, int $b) {
        $this->a = $a;
        $this->b = $b + 1;
    }
}
```

### 🙆‍♀全てのプロパティが昇格可能な場合は昇格させる。
```php
class A {
    
    public function __construct(
        private readonly int $a, 
        private readonly int $b
    ) {}
}
```


