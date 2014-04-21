Mustache Atomic Loader
======================

*A work in progress*

Mustache Atomic Loader is a implementation of mustache template loader with
few advanced markup options. By filtering template content before passing to
Mustache it is possible to effectively extend Mustache language with custom
markup.

This component is designed to work with
[custom Mustache engine wrapper](https://github.com/attitude/mustache-data-preprocessor).



Support for translation markup in Mustache
------------------------------------------

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

Atomic Design
-------------

Custom `getFileName()` method changes way to locate template files. Each partial
(or template) has its own directory. The partial directory might look like this
and can contain othe assets:

```
+ <abs partials path>/
  + elements/
    + page/
      + header/                # atom
        - template.mustache    # element's html/mustache template
        - styles.css           # element's styles (asset)
        - scripts.js           # element's behaviour (asset)
  + sections/
  + ...
```


```
{{ > elements-page-head }}
<!-- resolves to: <abs partials path>/elements/page/head/template.mustache -->
```

Loading the assets and cocatenation
-----------------------------------

By passing the `assets` option array in during class construction, you can load
any assets according to glob mask (e.g *.css, *.js). See `getAssetDefaults()`
method containing the example.

Assets are linked in the markup according to the asset template.

Loading each tiny bit of styles would be crazy on production. Therefore you can
use asset concatenation which is combining assets in one file, caching it in any
publicly accessible folder you specify.

Example Concantenation class is included. You might consider extending it and
and by defining custom `defaultConcantenateAssets()` method overwrite the
behaviour. Since v0.3.0 this class is able to concatenate linked as well as
inline assets into one file.

> **Heads up:** It is important to note, that this Concatenation class combines
> only assets defined in the current page source. This might be awkward, but
> it enables to load only styles necessary to render the current page.

Minification
------------

Combining assets is important site speed optimisation, but it can be pushed more
by source minification. So far, CSS and JS is supported but should work with
most of the languages with similar language structs.

Minification is performed always, although it can be constrained to trimming
unnecessary space from line beginnings and ends by passing option
`'minify' => false` in construct arguments.

Otherwise heavy minification is performed. Anything within double/single quoted
strings is considered protected. Non minification, however, is more 'dumb' than
the heavy version and does not respect indentation within quoted strings.

Example Usage
-------------

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

    $concantenator = new AtomicLoader_AssetsConcantenator(WWW_ROOT_DIR, $concatenation_args);
    $concantenator->active = isset($_GET['concantenate']) && $_GET['concantenate']==='false' ? false : true;

    $html = $concantenator->defaultConcantenateAssets($html);

    echo $html;
} catch (HTTPException $e) {
    $e->header();
    echo $e->getMessage();
}
```

---

Enjoy and let me know what you think.

[@martin_adamko](https://twitter.com/martin_adamko)
