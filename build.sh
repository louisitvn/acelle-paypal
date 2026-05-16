#!/bin/bash

# Notice: make sure you are at the correct tag/version


if [ -z "$1" ]
then
  echo "Error: please specify the output path" && exit 1
fi

if [ ! -d "$1" ]
then
  echo "Error: directory does not exist" && exit 1
fi

APPNAME="paypal"
VERSION=$(git tag --points-at HEAD)
# VERSION=${VERSION:-"-latest"}

if [ -z "$VERSION" ]
then
  echo "Error: no version (tag) found. Make a tag first, for example: git tag -a 1.0.0 -m 'My First release'" && exit 1
fi

APPDIR="$APPNAME"
APPZIP="$APPNAME-$VERSION.zip"
OUTPUT="$1/$APPDIR"

if [ -d "$OUTPUT" ]; then
  echo "Error: directory [$OUTPUT] already exists" && exit 1
fi

mkdir $OUTPUT
cp -r ./* $OUTPUT/
cd $OUTPUT/
rm build.sh
zip -r $APPZIP ./*
mv $APPZIP ../
cd ..
rm -fr $APPDIR
echo "$APPZIP created"
