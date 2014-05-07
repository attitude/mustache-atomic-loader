Mustache Atomic Loader
======================

*A work in progress*

Mustache Atomic Loader is a implementation of Mustache template loader with
few advanced markup options. By filtering template content before passing to
Mustache it is possible to effectively extend Mustache language with custom
markup.

This component is designed to work with
[custom Mustache engine wrapper](https://github.com/attitude/mustache-data-preprocessor).



1/ Support for translation markup in Mustache
--------------------------------------------

You can use the extra markup for template translation included in this class.
This markup is inspired by WordPress gettext (simplicity) and Angular.JS (form
of Angular filters).

Form for translating strings like labels: `{{ 'string' | translate }}`, e.g.:

```
{{ 'Event' | translate }}
```

Translating with pluralisation: `{{ count ? 'singular' : 'plural' }}`, e.g.:

```
{{ people ? 'One person' : '{} people' | translate }}
```

2/ Atomic Design
---------------

Custom `getFileName()` method changes way to locate template files. Each partial
(or template) has its own directory. The partial directory might look like this
and can contain other assets:

```
+ <abs partials path>/
  + elements/                # a group with common purpose
    + page-header/           # element
      - template.mustache    # element's html/mustache template
      - styles.css           # element's styles (asset)
      - scripts.js           # element's behaviour (asset)
  + sections/
  + ...
```

```
{{> elements/page-head }}
<!-- resolves to: <abs partials path>/elements/page-head/template.mustache -->
```

This loader enforces near-flat structure of partials with only 2 levels of
directories: group of partials and partial directory (the template itself is
located in the folder named as the requested partial).

3/ Loading the assets and concatenation
--------------------------------------

By passing the `assets` option array in during class construction, you can load
any assets according to glob mask (e.g *.css, *.js). See `getAssetDefaults()`
method containing the example.

Assets are linked in the markup according to the asset template.

Loading each tiny bit of styles would be crazy on production. Therefore you can
use asset concatenation which is combining assets in one file, caching it in any
publicly accessible folder you specify.

Example Concatenation class is included. You might consider extending it and
and by defining custom `defaultConcatenateAssets()` method overwrite the
behaviour. Since v0.3.0 this class is able to concatenate linked as well as
inline assets into one file.

> **Heads up:** It is important to note, that this Concatenation class combines
> only assets defined in the current page source. This might be awkward, but
> it enables to load only styles necessary to render the current page.

4/ Macaw Loader
--------------

** I've written [a tutorial how to convert Macaw into Publisher](http://www.martinadamko.sk/posts/152).**

Template loader able to convert Macaw templates to usable Mustache templates.
There's of course need to mark objects in Macaw.

### 4.1 Supported markup:

- Repeat content of element – add class `.repeat-`**mustache-context**
- Non-false/non-empty display — `.only-if-`**mustache-context**
- False/empty sections — `.only-ifnot-`**mustache-context**
- Bind value to create dynamic class name — *.any-class-prefix-*`bind-value-`**mustache-context**

Replace **<mustache-context>** with data scope/context as when using mustache, e.g.:
`{{person.name}}`.

#### 4.1.1 **`.only-if-`** and **`.only-ifnot-`** Eample**:

DOM in Macaw:
  ```
  – h1.only-if-website-title
  – h1.only-ifnot-website-title
  ```

Exported HTML from Macaw:
```html
<h1>[website.title]</h1>
<h1>Default title</h1>
```

Converted to Mustache:
```html
{{#website.title}}
<h1>{{website.title}}</h1>
{{/website.title}}
{{^website.title}}
<h1>Default title</h1>
{{/website.title}}
```

Data (context/scope):
```yaml
website:
  title: Some Fancy Website Title
```

If website.title is non-false produces HTML:
```html
<h1>Some Fancy Website Title</h1>
```

Else if website.title is empty/falsy produces HTML:
```html
<h1>Default title</h1>
```

#### 4.1.2 **`.repeat-`** and **`.bind-value-`** Eample**:

DOM in Macaw:
```
– ul.statuses.repeat-statuses
– li.status.status-is-bind-value-type
```

Exported HTML from Macaw:
```html
<ul class="statuses repeat-statuses">
  <li class="status status-is-bind-value-type">[text]</li>
</ul>
```

Converted to Mustache:
```html
{{#hasStatuses}}
<ul class="statuses">
  {{#statuses}}
  <li class="status status-is-{{type}}">{{text}}</li>
  {{/statuses}}
</ul>
{{/hasStatuses}}
```

Data (context/scope):
```yaml
---
hasStatuses: 2
statuses:
-
  type: success
  text: Message sent OK
-
  type: error
  text: Mesage failed to send
...
```
> Note: `hasStatuses` is a feature of  [DataProcessor Mustache wrapper](https://github.com/attitude/mustache-data-preprocessor)
> and by default setting **hasItems** section is turned off. See [Macaw loader comment around line 548](https://github.com/attitude/mustache-atomic-loader/blob/develop/MacawLoader.php#L548).


Produces HTML:

```html
<ul class="statuses">
  <li class="status status-is-success">Message sent OK</li>
  <li class="status status-is-error">Message failed to send</li>
</ul>
```

---

### 4.2 camelCase

To trigger camelCase in the class markup in Macaw DOM use `_` on both ends
of attribute you need in camelCase. You can mix both and use it only on one:

- `parent-_sub_context_` >>> {{#parent.subContext}}
- `_parent_context_-_sub_context_` >>> {{#parentContext.subContext}}

**There's no need to camelCase in design.**

### 4.3 Swatches in LESS CSS

Macaw Loader can extrat used colors from exportec CSS from Macaw. The LESS file
is placed next to the `.mcw` file together with a HTML preview of all colours.

5/ Minification
--------------

Combining assets is important site speed optimisation, but it can be pushed more
by source minification. So far, CSS and JS is supported but should work with
most of the languages with similar language structs.

Minification is performed always, although it can be constrained to trimming
unnecessary space from line beginnings and ends by passing option
`'minify' => false` in construct arguments.

Otherwise heavy minification is performed. Anything within double/single quoted
strings is considered protected. Non minification, however, is more 'dumb' than
the heavy version and does not respect indentation within quoted strings.

6 Example Usage
---------------

```php
$db = FlatYAMLDB_Element::instance();

try {
    $engine = DataPreprocessor_Component::instance();
} catch (HTTPException $e) {
    $e->header();
    echo $e->getMessage();

    exit;
}

try {
    $collection = $db->getCollection($requestURI);

    $html = $engine->render($collection, $language['_id']);

    $concatenation_args = array(
        'publicDir' => WWW_ROOT_DIR,
        'publicURL' => '/',
        'publicStaticDir' => ASSETS_ROOT_DIR,
        'publicStaticURL' => ASSETS_URL,
        'assets' => AtomicLoader_FilesystemLoader::getAssetDefaults()
    );

    $concatenator = new AtomicLoader_AssetsConcatenator(WWW_ROOT_DIR, $concatenation_args);
    $concatenator->active = isset($_GET['concatenate']) && $_GET['concatenate']==='false' ? false : true;

    $html = $concatenator->defaultConcatenateAssets($html);

    echo $html;
} catch (HTTPException $e) {
    $e->header();
    echo $e->getMessage();
}
```

---

Enjoy and let me know what you think.

[@martin_adamko](https://twitter.com/martin_adamko)
