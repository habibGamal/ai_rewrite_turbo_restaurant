#!/bin/bash

# Step 4: Install npm dependencies
npm install

# Step 5: Build the project
npm run build

# Step 6: Delete all existing PM2 processes
pm2 delete pos

# Step 7: Move one directory up
cd ../

# Step 8: Rename the existing build folder with a timestamp
current_datetime=$(date '+%Y%m%d_%H%M%S')
mv ./build ./build_$current_datetime

# Step 9: Move the new build folder to the root build directory
mv ./turbo_resturant/build ./build

# Step 10: Copy uploads directory from old build to the new build
cp -r ./build_$current_datetime/public/images ./build/public
cp -r ./build_$current_datetime/public/manifest.json ./build/public
cp -r ./build_$current_datetime/logs ./build

# Step 11: Copy the .env file from the old build to the new build
cp ./build_$current_datetime/.env ./build/

cd ./build

npm ci --omit="dev"

# Step 12: Start the server using PM2
pm2 start bin/server.js --name="pos"

pm2 save
