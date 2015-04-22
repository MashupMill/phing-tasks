# phing-tasks
Collection of PHING tasks

## Loading via composer
```json
"require-dev": {
    "mashup-mill/phing-tasks": "dev-master"
}
```

## Importing tasks
```xml
<import file="./vendor/mashup-mill/phing-tasks/src/main/resources/tasks.xml"/>
```

## YuiCompressorTask

```xml
<yuic jar="./vendor/bin/yuicompressor.jar" 
      cacheFile="${project.target}/yuic.cache" 
      targetDir="${project.target}/min">
    <fileset dir="${project.target}">
        <include name="**/*.css" />
        <exclude name="**/*.min.css" />
    </fileset>
</yuic>
```