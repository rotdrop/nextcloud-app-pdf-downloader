#!/bin/bash

# Example do nothing filter script. It just passes PDF files through
# and otherwise bails out. This is just to demonstrate how filters
# could be implemented:
#
# --mime-type=MIME_TYPE as only argument
# STDIN/STDOUT for passing data.
# STDERR may be used for error messages

MIME_TYPE=

# options may be followed by one colon to indicate they have a required argument
if ! OPTIONS=$(getopt -u -o m: -l mime-type: -- "$@")
then
    # something went wrong, getopt will put out an error message for us
    exit 1
fi

set -- $OPTIONS

while [ $# -gt 0 ]
do
    case $1 in
        -n|--mime-type)
            MIME_TYPE="$2"
            shift
            ;;
        (--)
            shift
            break
            ;;
        (-*)
            echo "$0: error - unrecognized option $1" 1>&2
            exit 1
            ;;
        (*)
            break
            ;;
    esac
    shift
done

echo $MIME_TYPE

case $MIME_TYPE in
    application/pdf)
        cat
        exit 0
        ;;
    *)
        echo "Mime-type $MIME_TYPE not handled" 1>&1
        exit 1
        ;;
esac
