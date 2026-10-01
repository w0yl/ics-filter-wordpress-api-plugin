# Development Notes

## Publishing Releases

1. Make sure code is committed to the master branch and tested.

2. Create a zip file of the project root directory. The zip file structure should look like this:
    ```
    ics-filter-wordpress-api-plugin.zip
    └── ics-filter-wordpress-api-plugin/
        ├── ics-filter-api.php
        ├── DEVELOPMENT.md
        ├── LICENSE
        └── README.md
    ```

3. Create a new release.

    - Create a new tag named `v1.2.3` (where 1.2.3 is the version number).
    - Start the release title with `v1.2.3` (where 1.2.3 is the version number) followed by a short descriptive title.
    - Attach the zip file as an asset.

[!IMPORTANT]
It is crucial for the release asset to be named `ics-filter-wordpress-api-plugin.zip` as other code relies on this and things will break otherwise.
