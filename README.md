# SuluSyncBundle

## Installation
`composer require fusonic/sulu-sync`

With a healthy Sulu installation, install this bundle and add it to your `AbstractKernel.php`: 

```
...
new Fusonic\SuluSyncBundle\FusonicSuluSyncBundle(),
...
```

## Usage

In sulu-standard, the console file is in the `app` folder.

In sulu-minimal, the console file is in the `bin` folder.

### Export contents
To export contents, use `(app|bin)/console sulu:export`. 
![Export command](http://i.imgur.com/AGOziOH.gif)

### Import contents
To import contents, use `(app|bin)/console sulu:import <host>`. Downloading assets might take a while, depending on the size of the `uploads` directory. 
![Import command](http://i.imgur.com/nIn58vp.gif)


To import everything without uploads (images etc.), use `(app|bin)/console sulu:import <path> --skip-assets`. 

To import everything without Elastic Search indices, use `(app|bin)/console sulu:import <path> --skip-indices`. 
This will speed up the import. 

To export everything, when having no uploads or Elastic Search indices in Sulu content, use `(app|bin)/console sulu:export <path> <export_msg>`

To export everything with uploads, use `(app|bin)/console sulu:export <path> <export_msg> --export-assets`

To export everything with Elastic Search indices, use `(app|bin)/console sulu:export <path> <export_msg> --export-indices`