#!/bin/bash

# Merlin 2015-08-24 force use of python2.7
shopt -s expand_aliases
alias python="python2.7"

if [ $# -eq 1 ]
then
    sf_user=$1
fi

# checking Python version
PYV=`python -c 'import sys; print(hex(sys.hexversion))'`
if (( "$PYV" <= "0x2070000" ))
then
  echo you are using python version:
  python --version
  echo "MolProbity requires Python 2.7 or newer";
  exit
fi

if [ ! -f base.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/base.tar.gz -o base.tar.gz; fi
tar zxf base.tar.gz

if [ ! -d sources ]; then mkdir sources; fi
if [ ! -d build ]; then mkdir build; fi
cd sources

echo getting sources ...
if [ ! -f boost.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/boost.tar.gz -o boost.tar.gz; fi
if [ ! -f scons.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/scons.tar.gz -o scons.tar.gz; fi
if [ ! -f annlib.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/annlib.tar.gz -o annlib.tar.gz; fi
if [ ! -f annlib_adaptbx.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/annlib_adaptbx.tar.gz -o annlib_adaptbx.tar.gz; fi
if [ ! -f cdflib.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/cbflib.tar.gz -o cbflib.tar.gz; fi
if [ ! -f ccp4io.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/ccp4io.tar.gz -o ccp4io.tar.gz; fi
if [ ! -f ccp4io_adaptbx.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/ccp4io_adaptbx.tar.gz -o ccp4io_adaptbx.tar.gz; fi
if [ ! -f chem_data.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/chem_data.tar.gz -o chem_data.tar.gz; fi
if [ ! -f lapack_fem.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/lapack_fem.tar.gz -o lapack_fem.tar.gz; fi
if [ ! -f tntbx.tar.gz ]; then curl http://kinemage.biochem.duke.edu/molprobity/tntbx.tar.gz -o tntbx.tar.gz; fi

echo unpacking sources ...
tar zxf boost.tar.gz
tar zxf scons.tar.gz
tar zxf annlib.tar.gz
tar zxf annlib_adaptbx.tar.gz
tar zxf cbflib.tar.gz
tar zxf ccp4io.tar.gz
tar zxf ccp4io_adaptbx.tar.gz
tar zxf chem_data.tar.gz
tar zxf lapack_fem.tar.gz
tar zxf tntbx.tar.gz

if [ -n "$sf_user" ]
then
    svn --non-interactive --trust-server-cert co https://$sf_user@svn.code.sf.net/p/cctbx/code/trunk cctbx_project
else
    svn --non-interactive --trust-server-cert co https://svn.code.sf.net/p/cctbx/code/trunk cctbx_project
fi

#svn --non-interactive --trust-server-cert co https://quiddity.biochem.duke.edu/svn/reduce/trunk reduce
#svn --non-interactive --trust-server-cert co https://quiddity.biochem.duke.edu/svn/probe/trunk probe
#svn --non-interactive --trust-server-cert co https://quiddity.biochem.duke.edu/svn/suitename

svn --non-interactive --trust-server-cert co https://github.com/rlabduke/probe.git/trunk probe
svn --non-interactive --trust-server-cert co https://github.com/rlabduke/reduce.git/trunk reduce
svn --non-interactive --trust-server-cert co https://github.com/rlabduke/suitename.git/trunk suitename

cd ../build

echo creating Makefile
#this script, at minimum, creates the Makefile for the make operation that follows
python ../sources/cctbx_project/libtbx/configure.py mmtbx

#As of this writing, the default make command below evaluates to:
#./bin/libtbx.scons -j "`./bin/libtbx.show_number_of_processors`"
#comment in the similar line below to build with a manually chosen number of processors,
#otherwise "make" will use all processors on the machine (which may be ok)
#Compilation has at least one memory-heavy step such that <= 1GB memory / processor
#will cause compilation to delve into virtual memory (ultrabad)

#slow but safe, (command is fragile b/c copied from an autogenerated make file, check build/Makefile if broken
#./bin/libtbx.scons -j 1

echo making ...
#fast but may be memory intensive
make

source ../build/setpaths.sh

#this configures all the rotamer and ramachandran contour files so rotalyze and ramalyze work.  They are downloaded as hiant text files, this line of code creates them as python pickles
mmtbx.rebuild_rotarama_cache

# git doesn't store empty directories so we're adding the the required empty directory here.
cd ..
mkdir -p public_html/data
mkdir -p public_html/data/tmp
mkdir -p feedback
mkdir -p tmp
