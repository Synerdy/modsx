# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email szajens@gmail.com with a description of
the problem and, if possible, steps to reproduce it. You can expect an
acknowledgement within a few days.

## Scope notes

This package is a command-line tool that copies and deletes directories inside
the project it is installed in. It is intended to be run by a developer with
write access to that project. Running it from a web request, or exposing its
commands to untrusted input, is outside its intended use.
