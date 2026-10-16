# Notes

<format> is required and I do not have it and media type in data at the moment so is skipped

```
{% if elements.download_url is not empty %}
{% for url in elements.download_url |split (', ') %}
<distribution>
    <distribution_downloadable_file>
        <title>{{ url }}</title>
        <access_url>
            <iri>{{ url }}</iri>
        </access_url>
    </distribution_downloadable_file>
</distribution>
{% endfor %}
{% endif %}
```